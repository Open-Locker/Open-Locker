# ADR-0040: Separate command acknowledgement from door-open detection

> **Renumbered from ADR-0031** — ADR numbers were deduplicated and put in date order from 0018 up; see #214.

## Status

Accepted

## Date

2026-07-26 (accepted 2026-09-19 after RS485 locker-board implementation narrowed client outcomes)

## Context

The locker client reports `open_compartment` with `result: success` once the
unlock actuation has been sent. The backend derives open-request progress from
that response and from follow-up device events.

That conflates two different facts:

1. **The command executed.** The lock received its actuation pulse.
2. **The door is physically open.** The compartment is actually accessible.

A successful actuation does not imply the second fact. The lock can release while
the door stays shut — nothing pulled it, something is jammed against it, the
latch is worn, or a hand is holding it closed.

The hardware exposes door position through polled sensors. The client maps them
to `open` / `closed` / `unknown` and publishes retained compartment snapshots
(ADR-0016). Post-actuation detection connects those observations back to the
open request that caused them.

The same separation supports **uncommanded opens**: a door that opens with no
recent actuation is distinguishable from a normal open when snapshot polling and
relay-fire correlation are wired together (see `state-publishing` and
`RelayFireLog`).

### Constraints

- Backend domain behaviour stays event sourced (ADR-0033).
- `state/compartments` remains the source of truth for **current** door state
  (ADR-0016).
- Snapshots are **change-only** (ADR-0038).
- External message contract changes require AsyncAPI/JSON Schema updates and
  contract tests (ADR-0015, ADR-0019).

## Decision

### 1. The client decides whether the door opened — after actuation

Door-open detection lives in the locker client's application layer, in
`OpenCompartmentUseCase`. After actuation succeeds, the client polls door sensors
through `LockerBusPort.readDoorSensors()` and reports the outcome.

The backend does **not** infer physical opening by correlating snapshot deltas
with pending commands.

There is **no synchronous pre-actuation door read** and no
`LockerBusPort.readRelayState()` monitoring. Relay coil state is not part of the
open flow.

### 2. Open sequence

```text
resolve mapping
→ ensure bus connection
→ send actuation (flashRelay)
→ return / acknowledge command (pulse sent)
→ poll door sensors until timeout
→ emit opened or door_jammed
```

Actuation acknowledgement and physical door outcome remain separate messages.
The RS485 unlock response must never directly emit `opened` or `door_jammed`;
those require door-sensor observation (ADR-0061).

### 3. Detection timeout follows the bank's heartbeat interval

The client waits up to `heartbeat_interval_seconds` (default 10) for the door to
open. The value is delivered through `apply_config`; no dedicated detection
column is introduced.

The timeout is a patience limit, not a delay: a door that opens at 2 s is
reported at 2 s.

### 4. Each fact is reported when it happens

The client does not hold its command response until detection completes:

- Actuation succeeded → existing `open_compartment` success on
  `locker/{uuid}/response` means **the pulse was sent** and nothing more.
- Detection outcome → device events on `locker/{uuid}/event` (`event.json`
  envelope).
- Uncommanded open → same events channel, with no command behind it.

No second `command_response` is emitted for the same transaction.

### 5. Client detection outcomes

The **current locker client** emits only:

| Outcome        | Event                         | Meaning                                      |
| -------------- | ----------------------------- | -------------------------------------------- |
| `opened`       | `compartment_open_detected`   | Door observed `open` within the window.      |
| `door_jammed`  | `compartment_open_failed`     | Door never observed `open` within the window.|

The client does **not** perform a pre-actuation read and does **not** emit new
`already_open` outcomes. If the door was already open before the pulse,
post-actuation polling still reports `opened` when the sensor reads `open`.

**Contract compatibility:** `compartment_open_detected` with
`outcome: already_open` remains in the AsyncAPI schema and examples as
historical and backend-supported vocabulary. Older clients or manual replays may
still produce it; the backend records it as its own deviation. New locker-client
releases follow the two-outcome table above.

### 6. Correlation anchors on the last relay fire, unbounded

A door-open observation is linked to the **most recent relay fire** for that
compartment, with no time cutoff, and the elapsed delta is recorded on the
event. If a compartment has never had a relay fire, the open is unlinked and
therefore uncommanded.

The relay fire — not the command — is the anchor: a command that errored before
reaching the lock never touched it and cannot explain a door opening.

Thresholds for alerting live in policy, not in the event stream.

### 7. Backend statuses mirror the device vocabulary

From `sent` onward the open-request status derives from what the device reported.
Earlier states are backend-only lifecycle.

| Status         | Origin  | Meaning                          |
| -------------- | ------- | -------------------------------- |
| `requested`    | backend | Open requested by an actor.       |
| `accepted`     | backend | Authorized.                       |
| `denied`       | backend | Refused by policy.                |
| `sent`         | backend | Command published to MQTT.        |
| `acknowledged` | device  | Relay fired. **Was `opened`.**    |
| `opened`       | device  | Door confirmed open.              |
| `already_open` | device  | Historical / legacy client path.  |
| `door_jammed`  | device  | Door never opened.                |
| `failed`       | device  | Command errored.                  |

`CompartmentOpenRequest.status` is a string-backed PHP enum with a `label()`
returning `__()`, matching `CompartmentDoorState`.

**`opened` changes meaning** for requests created after deployment: it means the
door was observed open, not merely that the pulse was sent. Existing rows are not
retro-interpreted.

### 8. Alerting

Every deviation — `door_jammed`, `already_open` (when present), and an
uncommanded open — is surfaced through admin UI, live operator notification, and
email to holders of `compartment.open`. A successful open alerts nobody.

The badge matters because a jammed compartment can still report `door_state:
closed`; the fault is in the lock, not the door position.

## Rationale

**Why the client decides.** It holds the sensor, the command context, and the
timing. The backend has no hardware port.

**Why no pre-read or `already_open`.** Delaying actuation to classify prior door
state added complexity without a current product outcome. Skipping pre-read keeps
the open path fast; the backend retains `already_open` for historical events.

**Why no relay-state monitoring.** Waveshare native hardware flash and the
proprietary RS485 board each own actuation timing. Polling relay output without
acting on a stuck coil added complexity without a product outcome.

**Why immediate actuation acknowledgement.** Holding the response until
detection completes would delay all feedback to the timeout.

**Why the heartbeat interval as the timeout.** Human reaction time after the
click typically fits within ten seconds; reusing an existing per-bank value avoids
a new knob (see Risks).

## Alternatives Considered

### Alternative A: Backend correlates snapshot deltas with pending commands

- Pros: No client change; the backend already receives door-state deltas.
- Cons: Detection latency depends on publish timing; places hardware inference
  in a component with no sensor; change-only snapshots complicate correlation.
- Why not chosen: The client already reads sensors during detection.

### Alternative B: Client holds the command response until detection completes

- Pros: One message per command.
- Cons: Every failure path waits the full timeout; conflates two facts again.
- Why not chosen: Penalises the failure path where feedback matters most.

### Alternative C: Second `command_response` for the same transaction

- Pros: Reuses the existing response channel.
- Cons: Duplicate responses break inbox dedup (ADR-0002).
- Why not chosen: Ruled out by issue #94.

### Alternative D: Dedicated `open_detection_timeout_seconds` column

- Pros: Independent of heartbeat tuning.
- Cons: Another knob to migrate and explain.
- Why not chosen: Deferred; heartbeat interval is the starting point.

### Alternative E: Synchronous pre-read with `already_open` emission

- Pros: Distinct outcome when the door was already open before the pulse.
- Cons: Extra bus read on every open; product no longer treats the distinction
  as useful; change-only snapshots made backend-only correlation brittle.
- Why not chosen: Removed in favour of the post-actuation sequence above; backend
  retains historical `already_open` handling (ADR-0061 narrows this ADR).

## Consequences

### Positive

- `opened` is a statement about the physical world rather than about actuation.
- Jams and blocked doors become visible instead of being recorded as successes.
- Uncommanded opens remain detectable when snapshot correlation is enabled.
- Detection is testable end to end without hardware via the simulator.
- RS485 and Waveshare share the same application-layer open semantics.

### Negative

- Device events, AsyncAPI schemas, and contract tests must stay aligned.
- `status` includes legacy values; mobile and admin consumers must handle the
  full set.
- "Opened" arrives later than actuation acknowledgement; UIs must reflect that.

### Risks

- **The `events` queue must stay single-worker** where reactors derive idempotent
  state from device events (see compose worker configuration).
- **Timeout coupling.** Raising `heartbeat_interval_seconds` for unrelated reasons
  lengthens door-detection waits.
- **Sensor faults present as jams.** A failed door sensor reports `unknown` and
  may surface as `door_jammed`.

## Rollout / Migration

- **Historical data:** Rows with status `opened` written before this meaning change
  record that the pulse was sent, not that the door was observed open.
- **New clients:** Post-actuation `opened` / `door_jammed` only; no new
  `already_open` from the locker client.
- **Backend:** Continue to accept `already_open` in device events for backward
  compatibility.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none
- Narrowed by: ADR-0061 (no new client `already_open`; RS485 actuation vs door
  state)

## References

- Related issues: #94
- Related docs:
  - ADR-0002 (message-id and transaction-id separation)
  - ADR-0015 (MQTT contract via AsyncAPI and JSON Schemas)
  - ADR-0016 (retained compartment snapshot and door-state persistence)
  - ADR-0019 (contract validation through component test suites)
  - ADR-0024 (locker-client v2 hexagonal rewrite)
  - ADR-0033 (synchronous projectors, queued reactors)
  - ADR-0038 (batched door polling and change-only snapshots)
  - ADR-0061 (backend-managed RS485 hardware profile; narrows client outcomes)
