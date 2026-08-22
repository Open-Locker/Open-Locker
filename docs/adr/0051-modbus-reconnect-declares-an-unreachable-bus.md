# ADR-0051: Modbus reconnect declares an unreachable bus and keeps recovering

## Status

Proposed

## Date

2026-08-22

## Context

`ReconnectCoordinator` allows five attempts, and `attempts` is reset only by a
successful connect. After five consecutive failures — roughly twenty-five seconds
with a USB adapter unplugged — the counter stays at five, and every later attempt
short-circuits on `attempts >= maxAttempts` and throws immediately. Nothing ever
lowers it again.

So a Pi that loses its adapter for half a minute stays unable to reach its
hardware until the container is restarted, however long the adapter has been back.
Restarting is the only recovery, and nobody is standing next to the locker.

Three smaller faults sit alongside it:

- **Startup gets one attempt.** `connect()` calls `connectInternal()` directly
  rather than through the coordinator, so a port that is not ready at boot is
  never retried, while the same failure a minute later is.
- **Reconnectable errors are recognised by message text.**
  `isReconnectableModbusError()` falls back to `message.includes('Port Not Open')`
  or `'ECONNREFUSED'`. An unplugged adapter surfaces as `ENOENT`, `EIO` or
  `ENXIO`, none of which match, so the most recoverable failure of all is
  classified as permanent and no reconnect is attempted. #173 removed the
  mirror-image fault — a substring that matched too much — from the same layer.
- **The state machine cannot express "dead".** `ConnectionState` is
  `disconnected | connecting | connected`, and `connectInternal()` sets
  `connecting` before dialling. When the attempt fails the state stays
  `connecting`, so a permanently unreachable bus is indistinguishable from one
  that is mid-attempt.

What already works is the reporting path: the heartbeat carries
`modbus_connected` (`state === 'connected'`), the backend stores it, and the admin
panel renders a "Hardware" badge of reachable / unreachable / unknown. An operator
can already see a dead bus. It is the client's own notion of its state, and its
ability to recover, that are wrong.

ADR-0014 faced the same question for the MQTT link and chose unlimited automatic
reconnects, with a finite cap available as "an optional safety cap for lab use".
The two transports in the same process currently disagree about whether
permanent surrender is acceptable on hardware in the field.

## Decision

**1. The attempt budget is per outage, not per process.**

A budget that cannot be replenished is a latch, and nothing in the system is
meant to latch. Attempts reset on a successful connect, as now, and additionally
once a cooldown has passed since the last failed cycle. A new cycle then starts on
the next operation that needs the bus.

The cooldown defaults to **60 seconds** and is exposed as
`modbus.reconnectCooldownSeconds` in `locker-config.yml`, validated through
`requireBoundedSetting` between 5 and 3600 — the same bounds and default as
`mqtt.keepaliveSeconds`, which is the closest existing analogue. Sixty seconds is a
compromise, not a measurement: long enough that a dead adapter is not dialled
continuously, short enough that a blip costs one poll interval.

The periodic compartment poll already drives bus traffic, so recovery needs no
timer of its own: the next poll after the cooldown starts a fresh cycle, and a
bus that has come back is used again without anyone intervening.

**2. Exhausting a cycle declares the bus unreachable, out loud.**

`ConnectionState` gains `unreachable`. It is set when a cycle is spent, and it
means "we tried, we failed, and we have stopped trying *for now*" — as opposed to
`connecting`, which claims an attempt is in progress.

Nothing changes on the wire. `modbus_connected` is one boolean derived from
`state === 'connected'`, and it is already `false` while a dial is in progress, so
the admin panel shows the same "unreachable" badge before and after this change.
The new state is for the client's own correctness: it is what lets a spent cycle be
distinguished from an attempt in flight, in the logs and in the code that decides
whether to start another cycle.

The actor owns the state and the coordinator owns the budget, so the transition is
made in **both** failure paths: `ensureConnected()`'s catch, and
`runWithReconnectRetry()`'s. The second is the one that matters — `ensureConnected()`
is reached only from the command path, while the compartment poll reaches the bus
through `readDoorSensors()`. Wiring only the first would leave a bus that dies
between commands never reporting `unreachable` at all, and would undercut decision
1, whose recovery trigger is that same poll.

**3. Recovery is automatic and silent about its attempts.**

Entering `unreachable` is logged at error once per cycle, not per attempt. A bus
that is down for an hour must not produce an hour of identical error lines — that
is how real failures get missed. Individual retry attempts stay at warn, as now.

**4. Startup retries within one bounded cycle.**

`connect()` goes through the coordinator, so a port that is not ready at boot gets
the same cycle of attempts as any later drop. It gets exactly one cycle: when that
is spent, startup continues with the bus marked `unreachable` rather than blocking.

`reloadRuntimeConfig()` dials the same way when the driver is closed, and is
included for the same reason: a config reload arriving while the adapter is briefly
away should not be the one path that still gets a single attempt.

ADR-0006 already decided that unreachable boards must not prevent the client from
starting; retrying forever at boot would quietly reverse that.

This narrows ADR-0006 in one place. That ADR fails startup when every configured
board fails the failsafe, and `runStartupFailsafe` throws accordingly. That stays
right when the bus is reachable and the boards are silent — a wiring or
configuration fault, which a human must fix and which should be loud. It is wrong
when the bus itself is unreachable: that is recoverable, and it is what this ADR
exists for. The failsafe therefore skips its sweep and logs once when the bus is
`unreachable`, instead of throwing.

Today that throw reaches `main().catch(process.exit(1))`, so a missing adapter at
boot restarts the process. That is a restart loop rather than a permanent latch —
it does recover when the adapter returns — but it churns the MQTT session, flaps
the bank in the admin panel, and fills the log with the same failure.

**5. Reconnectable failures are recognised by error code.**

Classification uses the codes the serial layer actually reports — `ENOENT`,
`ENXIO`, `EIO`, `EBADF`, `ECONNREFUSED` — read from the error and from a nested
`cause`, since a code that cannot be reached classifies as unknown. `Port Not Open`
stays a message match: it is the library's own wording and carries no code.

`EACCES` is deliberately absent. A device the container user may not open — a
missing `dialout` group — produces it every time, so treating it as recoverable
would cycle against it indefinitely. That is precisely the loop this decision
warns about, and a permissions fault is better left loud than quietly retried.

There is no catch-all. An unrecognised error stays non-reconnectable: dialling
again in response to a fault we do not understand is how a wedged bus turns into a
loop.

## Rationale

The failure this ADR fixes is not that reconnect gave up. It is that giving up was
permanent while the cause was temporary. Separating the two — a bounded cycle that
declares failure, and an unbounded willingness to try again later — keeps the
useful half of each behaviour.

The declaration is a state rather than a log line because the code needs it, not
because the wire does: whether to begin another cycle is a decision, and deriving
it from log output is not an option. What the operator sees is unchanged, and
deliberately so — `modbus_connected` already says "not reachable", and the reason
it is not reachable has no consumer yet.

Classifying by code rather than by message follows the same reasoning as #173,
where a substring match reported unrelated faults as broken hardware: a message is
written for a human and can be reworded by a dependency without notice, while a
code is part of the interface.

## Alternatives Considered

### Alternative A: Retry forever, as the MQTT link does

- Pros: matches ADR-0014; simplest possible policy; a bus that returns is always
  picked up.
- Cons: never declares failure, so "unreachable" would have to be inferred from a
  log; a genuinely dead adapter looks the same as a slow one, permanently.
- Why not chosen: the issue asks for recoverable cycles, not unlimited ones, and
  the operator-facing badge is worth more than the small simplification.

### Alternative B: Keep the permanent budget and restart the process on exhaustion

- Pros: no coordinator change; the autoheal sidecar already restarts unhealthy
  containers (ADR-0028).
- Cons: turns a recoverable hardware blip into a full client restart, dropping the
  MQTT session and re-running startup; and it treats a peripheral fault as a
  process fault.
- Why not chosen: the client is healthy — its adapter is not.

### Alternative C: Publish a dedicated "bus unreachable" event

- Pros: explicit, and carries a reason string.
- Cons: new contract surface for something `modbus_connected` already conveys, and
  a second source of truth for the same condition.
- Why not chosen: the heartbeat already says it, and the admin already renders it.

### Alternative D: Keep classifying by message text, add the missing strings

- Pros: smallest possible change.
- Cons: the list is unbounded and silently version-dependent; the same approach
  produced the fault #173 removed.
- Why not chosen: codes are the stable half of the interface.

### Alternative E: Extend the heartbeat with a bus state or reason

Keep `modbus_connected` and add a nullable field saying *why* — attempting,
unreachable, never configured.

- Pros: an operator could tell "trying" from "given up" without reading logs, which
  is the one thing the badge cannot express.
- Cons: a contract change plus backend and admin work, to answer a question nobody
  has asked yet; and the client would need a reason vocabulary it does not have.
- Why not chosen: the badge already distinguishes reachable from not, which is what
  an operator acts on. Worth revisiting the first time someone asks why a bank has
  been unreachable for an hour — the state added here is what such a field would be
  derived from.

## Consequences

### Positive

- A bus that returns after any outage is used again, with no restart and no
  intervention.
- A dead bus is stated rather than implied, and reaches the admin panel through a
  path that already exists.
- Boot no longer depends on the adapter being ready at exactly the right moment.
- An unplugged adapter is finally classified as recoverable, which is what makes
  the rest of this work at all.

### Negative

- One more `ConnectionState` value for callers to consider; `unreachable` is not
  `connected`, so anything testing for equality is unaffected, but anything
  enumerating states must handle it.
- A bus that is genuinely dead retries quietly forever at cooldown intervals. That
  is the cost of never latching; the state and the once-per-cycle error line are
  what keep it visible.

### Risks

- **Cooldown too short** turns recovery into a slow retry loop against dead
  hardware; too long delays recovery after a brief blip. Hence the bounded config
  key: a deployment that finds 60 seconds wrong can change it without a new image.
- **Startup semantics** are the fragile part: a bounded cycle at boot preserves
  ADR-0006, but making that cycle long enough to be useful lengthens startup for
  a device whose adapter is missing.
- **Error codes are assumed, not observed.** The chosen list reflects what the
  serial layer is documented to report for a removed device; it has not been
  confirmed against a real adapter being unplugged. That confirmation belongs to
  the Raspberry Pi soak test (#169), and until it runs, a code we guessed wrong
  means no reconnect for that failure.

## Rollout / Migration

1. Add `unreachable` to `ConnectionState`; set it when a cycle is spent. The union
   is implemented by the simulator's `InMemoryLockerBus` as well, so this is a
   typed change across every bus implementation rather than one file.
2. Reset attempts after `modbus.reconnectCooldownSeconds` (default 60, bounds
   5–3600, validated like `mqtt.keepaliveSeconds`), so a spent budget cannot
   outlive its outage; start a new cycle from the next operation needing the bus.
3. Route both direct dial sites through the coordinator — `connect()` and
   `reloadRuntimeConfig()` — bounded to one cycle at startup.
4. Make `runStartupFailsafe` skip its sweep and log once when the bus is
   `unreachable`, keeping the zero-success throw for a reachable bus.
5. Classify by error code, reading a nested `cause` too, and extend
   `isModbusLibraryError` with the same codes so an unplugged adapter is reported
   as `MODBUS_ERROR` rather than `UNKNOWN_ERROR`.
6. Cover with a fake driver: a long outage recovers and the state returns to
   `connected`, a spent cycle reports `unreachable` from the poll path as well as
   the command path, an unreachable bus does not fail startup while a reachable bus
   with silent boards still does, a reload against a closed port retries rather
   than failing on the first attempt, each classified code reconnects while an
   unknown one does not, and the cooldown setting is rejected at load outside its
   bounds.

No configuration changes are *required*: `modbus.reconnectCooldownSeconds` is
optional and existing deployments inherit the default on their next image.

## Supersedes / Superseded By

- Partially supersedes:
  [ADR-0006](0006-best-effort-startup-failsafe-for-unreachable-modbus-boards.md) —
  its rule that startup fails when every board fails the failsafe no longer applies
  when the bus itself is unreachable. The rest of ADR-0006 stands.
- Related:
  [ADR-0014](0014-locker-client-mqtt-session-and-reconnect.md) (the same question
  for the MQTT link), [ADR-0028](0028-mqtt-listener-liveness-healthcheck.md)
  (the autoheal sidecar that restarts unhealthy containers).

Worth knowing when reading this: the MQTT session defaults to `clean: false`, so a
client that recovers from a long outage receives the commands queued during it,
with no age check. Whether those should be refused is #134, and this ADR makes the
situation more common by making long outages survivable.

## References

- Related issues: #172, #169 (hardware confirmation), #134 (stale command age)
- Code: `src/adapters/modbus/reconnect-coordinator.ts`,
  `src/adapters/modbus/waveshare-modbus-bus-actor.ts`,
  `src/domain/errors.ts`, `src/ports/locker-bus.port.ts`
