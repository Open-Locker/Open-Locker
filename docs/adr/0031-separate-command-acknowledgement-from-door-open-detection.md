# ADR-0031: Separate command acknowledgement from door-open detection

## Status

Proposed

## Date

2026-07-26

## Context

The locker client reports `open_compartment` with `result: success` once the
unlock pulse has been sent and initial relay monitoring has completed. The
backend derives `CompartmentOpened` from that response and the open-request
projector sets status `opened`.

That conflates two different facts:

1. **The command executed.** The relay coil was energised, so the lock received
   its pulse. This is what the client currently reports.
2. **The door is physically open.** The compartment is actually accessible.

A successful unlock pulse does not imply the second fact. The lock can release
while the door stays shut — nothing pulled it, something is jammed against it,
the latch is worn, or a hand is holding it closed. Today all of these are
recorded as `opened`.

The hardware already exposes both signals and the client already reads both:

- `LockerBusPort.readRelayState()` — the relay coil (output).
- `LockerBusPort.readDoorSensors()` — Modbus FC02 discrete inputs mapped to
  `open` / `closed` / `unknown` (input).

The door signal is polled every 500 ms (ADR-0030) and published as the retained
`state/compartments` snapshot (ADR-0016), where the backend turns per-compartment
deltas into `CompartmentDoorStateChanged`. That stream exists and works; it is
simply never connected back to the open request that caused it.

The same gap blocks a second capability the system needs: a door that opens with
**no** command behind it is currently indistinguishable from any other door-state
change. Operationally that is a break-in, tampering, a faulty lock, or a failing
sensor, and nobody is told.

There are four possible combinations of the two signals. The system currently
records only one of them.

| Relay fired | Door opened | Meaning                       | Recorded today |
| ----------- | ----------- | ----------------------------- | -------------- |
| yes         | yes         | normal open                   | `opened`       |
| yes         | no          | jam, blocked, held, bad latch | `opened` ❌    |
| no          | yes         | uncommanded open              | not recorded ❌ |
| no          | no          | idle                          | n/a            |

### Constraints

- Backend domain behaviour must stay event sourced (ADR-0028). New statuses,
  correlation results, and alerts are stored events projected into read models,
  not direct model writes.
- `state/compartments` remains the source of truth for **current** door state
  (ADR-0016). This decision does not change that.
- Snapshots are **change-only** (ADR-0030). A door that is already open and
  stays open publishes nothing.
- Any change to the external message contract requires an AsyncAPI/JSON Schema
  update and contract tests (ADR-0015, ADR-0018).

## Decision

### 1. The client decides whether the door opened

Door-open detection lives in the locker client's application layer, in
`OpenCompartmentUseCase`. After firing the relay it watches the door sensor
through the existing `LockerBusPort` and reports the outcome.

The backend does **not** infer physical opening by correlating snapshot deltas
with pending commands.

### 2. Detection timeout follows the bank's heartbeat interval

The client waits up to `heartbeat_interval_seconds` (default 10) for the door to
open. The value is delivered to the client through the existing `apply_config`
path; no new configuration column is introduced.

The timeout is a patience limit, not a delay: a door that opens at 2 s is
reported at 2 s.

### 3. Each fact is reported when it happens

The client does not hold its command response until detection completes. It
emits messages as facts occur:

- Relay fired → the existing `open_compartment` response on `locker/{uuid}/response`,
  unchanged, with its existing `success` / `error` semantics. It now means
  **"the pulse was sent"** and nothing more.
- Detection outcome → a **device event** on the existing events channel
  (`event.json` envelope: `message_id`, `event`, `timestamp`, `data`).
- Uncommanded open → the same events channel, with no command behind it.

No second `command_response` is emitted for the same transaction.

### 4. Three detection outcomes

The client reads the door sensor before firing so it can distinguish them:

| Outcome        | Meaning                                          |
| -------------- | ------------------------------------------------ |
| `opened`       | Door was closed and opened within the window.     |
| `already_open` | Door was already open before the pulse.           |
| `DOOR_JAMMED`  | Door never opened within the window.              |

The relay is fired in all cases, including `already_open`. Refusing to fire adds
edge cases without operational benefit.

`DOOR_JAMMED` is reused from the existing `MqttErrorCode` enum rather than
introducing `OPEN_NOT_DETECTED` as the issue originally proposed. The contract
already names this condition.

### 5. Correlation anchors on the last relay fire, unbounded

A door-open observation is linked to the **most recent relay fire** for that
compartment, with no time cutoff, and the elapsed delta is recorded on the event.
If a compartment has never had a relay fire, the open is unlinked and therefore
uncommanded.

The relay fire — not the command — is the anchor: a command that errored before
reaching the lock never touched it and cannot explain a door opening.

No "correlation window" is baked into the event stream. A 2-second delta reads as
a normal open and a 3-day delta reads as unrelated; the number carries the
meaning. Thresholds live only in alerting policy, where they can change without
rewriting history.

### 6. Backend statuses mirror the device vocabulary

From `sent` onward the open-request status derives from what the device reported.
Earlier states are backend-only lifecycle, before the device is involved.

| Status         | Origin  | Meaning                          |
| -------------- | ------- | -------------------------------- |
| `requested`    | backend | Open requested by an actor.       |
| `accepted`     | backend | Authorized.                       |
| `denied`       | backend | Refused by policy.                |
| `sent`         | backend | Command published to MQTT.        |
| `acknowledged` | device  | Relay fired. **Was `opened`.**    |
| `opened`       | device  | Door confirmed open.              |
| `already_open` | device  | Door was open before the pulse.   |
| `door_jammed`  | device  | Door never opened.                |
| `failed`       | device  | Command errored.                  |

`CompartmentOpenRequest.status` becomes a string-backed PHP enum with a
`label()` returning `__()`, matching `CompartmentDoorState`.

**`opened` changes meaning.** It previously meant "the pulse was sent"; it now
means the door was observed open. Existing rows are not retro-interpreted (see
Rollout).

### 7. Alerting

Every deviation — `door_jammed`, `already_open`, and an uncommanded open — is
surfaced the same three ways:

1. A **red badge with a warning icon** on the compartment in the admin panel,
   carrying a tooltip that explains the fault.
2. A **live danger toast**, broadcast over Reverb to operators on the panel.
3. An **email** to the same operators.

A jam is a maintenance problem and an uncommanded open is a security problem, but
both mean somebody has to walk to that locker, so neither is made quieter than the
other. A successful open alerts nobody.

The badge is necessary because a jammed compartment reports `door_state: closed`
exactly like a healthy one — the fault is in the lock, not the door position, so it
cannot be expressed as a door state.

Toasts are broadcast rather than stored. A stored Filament notification would need
a `notifications` table and the panel's database-notification feature, sitting
between two mechanisms that already do the job: the audit log (ADR-0026) is the
durable in-panel record, and email is what reaches someone who is not looking.

Recipients are users holding the `compartment.open` permission, whose definition
already covers *"receiving operational status updates"*. Today that is Managers
and Admins.

## Rationale

**Why the client decides.** It holds the sensor, the command context, and the
timing. The backend has no hardware port; making it infer a physical fact from a
delayed, change-only stream places hardware reasoning in a component that cannot
observe hardware.

The change-only snapshot rule makes backend correlation actively wrong in a
reachable case: a door that is already open publishes no delta, so a backend
correlator would report a failure for a compartment that is physically open. The
client reads current state directly and does not have this blind spot.

This also costs almost nothing structurally. `readDoorSensors` is already on
`LockerBusPort`, so detection is an application-layer change — no new port, no
new adapter, no hardware code touched. Both adapters already implement the port,
so the simulator (ADR-0027) can reproduce a jammed door with no hardware.

**Why immediate reporting.** Holding the response until detection completes would
delay all feedback to the timeout — for a jam, the mobile app would hear nothing
for the full window. Reporting each fact as it occurs keeps the success path fast
and gives the backend a complete timeline.

**Why the heartbeat interval as the timeout.** Detection is bounded by human
reaction time — hear the click, reach, pull — typically 1–3 s, up to ~8 s for a
distracted user. Ten seconds independently lands in the right range, and reusing
an existing, already-understood per-bank value avoids adding a knob. The coupling
is a known trade-off (see Risks).

## Alternatives Considered

### Alternative A: Backend correlates snapshot deltas with pending commands

- Pros: No client change; the backend already receives door-state deltas and
  already has the open-request read model.
- Cons: Blind to already-open doors because snapshots are change-only, so a
  physically open compartment is reported as a failure. Detection latency depends
  on publish timing. Places hardware inference in a component with no sensor.
- Why not chosen: The already-open blind spot is a correctness defect, not a
  tuning problem.

### Alternative B: Client holds the command response until detection completes

- Pros: One message per command; no follow-up event to correlate.
- Cons: Every failure path waits the full timeout before the caller hears
  anything; conflates two facts in one message again, just later.
- Why not chosen: Penalises the failure path precisely where feedback matters
  most.

### Alternative C: Second `command_response` for the same transaction

- Pros: Reuses the existing response channel and its handler.
- Cons: Duplicate responses per transaction break the inbox dedup contract
  (ADR-0002) and make the response's meaning ambiguous.
- Why not chosen: Explicitly ruled out by issue #94.

### Alternative D: Dedicated `open_detection_timeout_seconds` column

- Pros: Independent of heartbeat tuning; per-bank; adjustable without side
  effects.
- Cons: Another knob to document, test, migrate, and explain in the admin panel.
- Why not chosen: Deferred, not rejected. Reusing the heartbeat interval is a
  starting point; splitting it later is a small, isolated change (see Risks).

### Alternative E: Bounded correlation window (e.g. 60 s)

- Pros: A door opening long after a command is unambiguously uncommanded.
- Cons: Bakes a policy threshold into the event stream; changing it later cannot
  reinterpret history.
- Why not chosen: Recording the delta preserves the same information and keeps
  the threshold in alerting policy, where it belongs.

## Consequences

### Positive

- `opened` becomes a statement about the physical world rather than about the
  relay.
- Jams, blocked doors, and failing latches become visible instead of being
  recorded as successes.
- Uncommanded opens are detectable, which is the prerequisite for break-in and
  tampering alerts.
- Detection is testable end to end without hardware via the simulator.
- No new port or adapter; the production hardware path is untouched.

### Negative

- The MQTT contract grows new device events, with the corresponding AsyncAPI,
  JSON Schema, and contract-test work.
- `status` gains four values and changes the meaning of one, rippling into
  `CompartmentOpenStatusResource`, the Filament resource, and the mobile app.
- The mobile client must be regenerated and its open-flow UI reviewed, since
  "opened" now arrives later and can be followed by a failure outcome.

### Risks

- **Timeout coupling.** Raising `heartbeat_interval_seconds` for an unrelated
  reason (a flaky network) silently lengthens door-detection waits. Mitigation:
  documented here; if it bites, introduce Alternative D as its own column.
- **Alert recipients may be too broad.** `compartment.open` is held by Managers
  and Admins. If break-in alerts prove noisy for Managers, narrow to
  `lockerbank.configure` or introduce a dedicated permission. Deliberately
  recorded here as the expected first thing to change.
- **Sensor faults present as jams.** A failed door sensor reports `unknown` and
  will surface as `door_jammed`. Acceptable — both need a human — but jam rates
  should be watched per compartment to spot a failing sensor rather than a
  failing door.
- **Alert volume for uncommanded opens.** Any manual open of an unlocked or
  ajar compartment produces an alert. Mitigation: `already_open` is a distinct
  outcome, and thresholds live in alerting policy.

## Rollout / Migration

1. Client: add door-sensor detection to `OpenCompartmentUseCase`, emit the new
   device events. Both adapters already satisfy the port.
2. Simulator: add a per-compartment **jam mode**. `InMemoryLockerBus.flashRelay()`
   currently always sets the door to `open`, so the simulator can reproduce
   `opened`, `already_open`, and uncommanded opens, but **not** `DOOR_JAMMED` —
   the outcome this decision exists to detect. A jammed compartment fires the
   relay normally and leaves the door `closed`. Seedable from the scenario YAML
   and togglable from the interactive console. This is a feature within the
   simulator shape decided in ADR-0027, not a change to it.
3. Contract: add the detection event payload schemas, update
   `docs/asyncapi/mqtt.yaml`, extend contract tests.
4. Backend: new stored events for detection outcomes and uncommanded opens;
   `CommandResponseReactor` stops deriving `CompartmentOpened` from
   `open_compartment` success and derives `acknowledged` instead; projector
   handles the new statuses.
5. Notifications: mail notification for uncommanded opens, to holders of
   `compartment.open`.
6. Mobile: regenerate the RTK Query client, review the open flow for the later
   `opened` and the new failure outcomes.
7. Docs:
   - `docs/simulator.md` — jam mode: scenario field, console command, and a
     worked "reproduce a jammed door" walkthrough.
   - `locker-client/simulator-scenario.yml.example` — a jammed compartment.
   - `docs/app_communication.md` — the documented status set
     (`accepted|denied|sent|opened|failed`) is now stale in three places, and
     `opened` has changed meaning for API and broadcast consumers.

**Historical data.** Existing rows with status `opened` were written under the
old meaning and are not migrated or reinterpreted; they record that the pulse was
sent. The change takes effect for requests created after deployment.

**Fallback.** The detection events are additive. If detection proves unreliable
in the field, the backend can ignore the new events and continue treating
acknowledgement as the terminal state, without a client rollback.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: —
- Related issues: #94
- Related docs:
  - ADR-0002 (message-id and transaction-id separation)
  - ADR-0015 (MQTT contract via AsyncAPI and JSON Schemas)
  - ADR-0016 (retained compartment snapshot and door-state persistence)
  - ADR-0018 (contract validation through component test suites)
  - ADR-0021 (role-based access control)
  - ADR-0024 (locker-client v2 hexagonal rewrite)
  - ADR-0026 (admin audit log)
  - ADR-0027 (contract-aligned locker fleet simulator)
  - ADR-0028 (synchronous projectors, queued reactors)
  - ADR-0030 (batched door polling and change-only snapshots)
