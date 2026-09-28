# ADR-0067: RS485 lock board behind a protocol-neutral locker bus port

## Status

Proposed

## Date

2026-09-28

## Context

The locker client supports two locker boards behind `LockerBusPort`:

- the Waveshare `Modbus RTU Relay (D)`, a generic relay board that needs a
  custom connection board, extra wiring, and external flyback components
  (ADR-0004);
- the dedicated 8-channel RS485 electric-lock controller with DIP addressing,
  a proprietary hex protocol, and XOR/BCC framing (ADR-0061).

Open-Locker wants one reference build: fewer components, simpler addressing,
and a smaller configuration surface. The RS485 board is the better fit.

The port itself was designed around Waveshare:

- actuation is `flashRelay(target, durationMs)`, a timed relay pulse; the RS485
  adapter ignores the duration;
- actuation returns `void`, so the RS485 board's unlock response (`00` or `11`,
  about 500 ms after the command) is validated and then discarded;
- door state is only available through `readDoorSensors(slaveId, start,
  length)`, a Modbus-style register range the RS485 adapter emulates from its
  query-all frame;
- `initializeBoard` exists for the Waveshare startup `all relays off` failsafe
  (ADR-0006);
- boards are identified by the Modbus term `slaveId` throughout the port.

ADR-0061 deliberately kept the unlock response out of door state: it only
confirmed that the board accepted the command.

The port-and-adapter structure itself is valuable. Other locker controllers may
be supported later, and they should not have to pretend to be relays or Modbus
devices. The goal is to remove Waveshare-shaped assumptions, not the
abstraction.

Two existing defects affect unlocking:

- A response timeout is a reconnectable `HardwareTransportError`, and
  `runWithReconnectRetry` re-runs the whole operation after reconnecting. An
  unlock whose request reached the board but whose response was lost is sent a
  second time.
- Unlock priority over polling (ADR-0063,
  `0063-prioritize-compartment-unlock-actuation.md`) does not hold end to end.
  `RuntimeConfiguredLockerBus` wraps the adapter in a first-come, first-served
  queue that both unlocks and door reads pass through, so polls already waiting
  there run before a newer unlock.

## Decision

### 1. `LockerBusPort` stays and becomes protocol-neutral

The application talks only to `LockerBusPort`; board-specific behaviour stays
inside adapters. The port changes as follows:

- `flashRelay(target, durationMs): Promise<void>` becomes
  `unlockCompartment(target): Promise<UnlockObservation>`. `UnlockObservation`
  carries an optional `DoorState` observed directly after actuation; adapters
  without direct feedback return none.
- A failed unlock rejects with one of two typed errors:
  - `UnlockNotSentError`: no request byte left the client;
  - `UnlockOutcomeUnknownError`: the write had started, so the board may have
    actuated. This covers write and drain errors after the write began,
    response timeouts, BCC mismatches, and mismatched responses.
- `readDoorSensors(slaveId, startAddress, length)` becomes a read of the
  configured compartments' states on one board. Every failure rejects, with
  `BoardNotRespondingError` when the board stays silent; the snapshot poller
  maps any rejection to `unknown` for that board's compartments.
- `initializeBoard` is removed. An adapter that needs board preparation does it
  inside `connect()`.
- Boards are identified by a protocol-neutral `boardAddress`, and compartments
  by `address`. `CompartmentTarget.slaveId` and `relayAddress`, and
  `getConfiguredSlaveIds`, are renamed accordingly; the wire field `slaveId` is
  mapped at the configuration boundary.
- `open`, `closed`, and `unknown` remain the only door states the port returns.

Relay, coil, flash, and Modbus terminology is removed from ports, application
code, tracing, logs, health reporting, tests, and documentation, including
`RelayFireLog`, `recordFire`, and `BusOperationRecorder.recordFlashRelay`.

### 2. The RS485 board is the only production adapter

The Waveshare adapter, its flash-register driver, the startup `all relays off`
failsafe (`runStartupFailsafe`), the `flashDurationMs` setting, and the
`modbus-serial` dependency are removed. The in-memory simulator bus remains as
a second implementation of the port.

Dropping the failsafe assumes, from the vendor documentation, that an RS485
unlock is a board-timed momentary actuation that leaves no energised output
after a restart. If the reference build disproves this, the adapter performs an
equivalent reset in `connect()`.

Board reachability is still checked whenever `RuntimeConfiguredLockerBus`
connects an adapter, at startup from a stored configuration and on
`apply_config`: it reads every configured board, and fails with
`HARDWARE_ERROR` when the bus is connected but no configured board answers.

### 3. The unlock response is the first post-actuation observation

The RS485 adapter maps the unlock response through the bank's `feedback_type`,
the same polarity used for query-all:

- `door_closing`: `00` = `open`, `11` = `closed`
- `door_opening`: `11` = `open`, `00` = `closed`

`OpenCompartmentUseCase` uses that value as the first door-detection
observation. `open` reports `opened` immediately; `closed` or no observation
continues query-all polling; `door_jammed` is reported only when the detection
window ends without observing `open`.

Command acknowledgement and door events stay separate (ADR-0040). The client
still actuates when the door already reads `open` (#302); the unlock response
then reads `open` and `opened` follows about 500 ms after the command.

Query-all polling continues to detect manual and uncommanded openings.

### 4. An unlock is never re-sent automatically

Reconnect behaviour is unchanged: timeouts, BCC mismatches, and port errors stay
reconnectable, and ADR-0051 decides when the bus is `unreachable`. After an
`UnlockOutcomeUnknownError`, however, the reconnect does not re-run the unlock,
and the caller still receives that error even when the reconnect fails. Only
`UnlockNotSentError` may be retried after reconnecting.

Both errors fail the `open_compartment` command with a hardware error. After
`UnlockOutcomeUnknownError`, `OpenCompartmentUseCase` records the attempt as a
possible actuation, so the state poller does not report an opening within the
detection timeout as uncommanded. No detection window starts and no `opened` or
`door_jammed` follows the error response.

Command deduplication by `message_id` and `transaction_id` stays (ADR-0002,
ADR-0004).

### 5. `adapter_type` stays as the extension point

`adapter_type` remains required in `apply_config`, with `rs485_lock_board` as
its only accepted value, so the `config_hash` serialization from ADR-0061 is
unchanged. Once existing rows are migrated, the backend admin no longer offers
an adapter choice.

The client rejects `waveshare_modbus`. A stored `waveshare_modbus` or legacy
overlay selects no adapter: the client runs without a hardware bus, reports
`modbus_connected: false`, and fails opens with `RUNTIME_CONFIG_NOT_APPLIED`
until the configuration is sent again from the admin panel.

### 6. External configuration is reduced to the serial device

The RS485 adapter fixes its serial settings at 9600 baud, 8 data bits, no
parity, 1 stop bit, and no flow control. Internal defaults:

- response timeout: 1500 ms;
- frame quiet time: 25 ms of silence completes a response, because USB-RS485
  adapters deliver one response in bursts that can be more than 5 ms apart;
- inter-transaction gap: 5 ms (ADR-0035 for 9600/8N1);
- reconnect budget and cooldown (ADR-0051).

The external YAML key becomes `serial:` with a single `port` entry, preferably a
stable `/dev/serial/by-id/...` path. `modbus:` remains a deprecated alias whose
`port` is read; its other keys are ignored with a warning.

### 7. Protocol handling

- XOR/BCC is validated over every complete frame.
- Query-all response length comes from the received frame, never from a
  declared channel count; only configured channels are selected.
- Missing status bits produce `unknown`.
- `slaveId` is the physical DIP address, `1..31`.
- Zero-based compartment addresses become one-based wire channels inside the
  adapter.
- Polling uses query-all; unsolicited `0x82` feedback is not supported.
- Full-open and multi-channel unlock commands are not exposed.

### 8. Transaction ordering and isolation

- Serial transactions run one at a time in priority order, unlocks before
  polling, and in submission order within a priority.
  `RuntimeConfiguredLockerBus` keeps its queue for adapter swaps but passes
  each operation's priority through.
- After a timeout, a BCC mismatch, or unexpected bytes, the adapter waits for
  the frame quiet time and discards all buffered input before the next request.
- A frame that does not match the pending request's header, board address, and
  channel is rejected.
- A USB-RS485 disconnect is recovered in the running process through the
  ADR-0051 reconnect cycle.
- A board that stays silent still triggers the reconnect, but is not queried
  again until the next poll, so an unlock waits at most one response timeout
  behind it.

### 9. Polling capacity

Polling stays sequential across boards, tolerates a failing board (ADR-0007),
and runs every 500 ms (ADR-0038). One board per bus is the supported
configuration until a poll cycle with several boards has been measured on a
real bus. The limit is documented, not enforced, so that measurement remains
possible.

### Out of scope

The contract names `slaveId`, `address`, `modbus_connected`, and
`milliseconds_since_last_relay_fire` stay. Renaming them changes the backend,
the mobile app, and every deployed client, and needs its own ADR.

## Rationale

Keeping the port preserves the extension point while letting the only
production adapter use its real capabilities. An optional observation fits
controllers with and without direct feedback.

Per the vendor documentation, the unlock response and query-all read the same
feedback input, so the unlock response reports an open door up to one poll
interval sooner without new signal semantics. If the reference build shows
otherwise, the adapter returns no observation and nothing else changes.

A lost unlock response must not cause a second actuation, and the protocol has
no transaction identifier to make a re-send safe. Only the port knows whether a
write started, so it reports that through the error type. Keeping reconnects
gives a hung USB adapter a chance to recover. A silent board is not reported as
`unreachable` either way, because the port reopens successfully.

A longer quiet time is preferred over completing frames by length and BCC:
query-all has no fixed length, and a truncated frame can pass a one-byte XOR
check by chance.

A stored Waveshare profile is not reinterpreted as RS485, because that would
send RS485 frames to a Modbus relay board. The `modbus:` alias keeps deployed
configuration files valid.

## Alternatives Considered

### Couple the application directly to the RS485 driver

- Pros: less indirection
- Cons: future controllers need an application-layer rewrite
- Why not chosen: the port is the intended extension point

### Keep Waveshare as a second supported adapter

- Pros: existing installations keep working
- Cons: two BOMs and wiring guides, and a relay-shaped port
- Why not chosen: the goal is one validated reference build

### Keep the unlock response as acknowledgement only (ADR-0061)

- Pros: no change to door detection
- Cons: discards a reading the board already provides
- Why not chosen: it comes from the same feedback input as polling

### Report a lost unlock response as success with unknown state

- Pros: no error when the lock did release
- Cons: reports acceptance the board never confirmed
- Why not chosen: command results must reflect what the client observed

### Treat timeouts as transaction failures without reconnecting

- Pros: fewer port reopenings
- Cons: a hung USB adapter is never reopened
- Why not chosen: reopening is the only in-process recovery for a hung adapter

### Remove `adapter_type` from the contract

- Pros: smaller payload
- Cons: changes `config_hash` and forces a coordinated rollout
- Why not chosen: one allowed value keeps the extension point at no cost

## Consequences

### Positive

- one production adapter, one BOM, and one wiring guide
- `opened` can be reported directly from the unlock response
- a lost unlock response no longer causes a second actuation
- unlocks take priority over queued polls end to end
- operators configure only the serial device path

### Negative

- Waveshare installations stop working on client versions with this change
- the backend must stop offering `waveshare_modbus` and migrate existing rows
- a lost unlock response fails the command instead of retrying it
- existing `modbus.*` settings other than `port` are ignored
- a client started from a Waveshare or legacy overlay cannot report that its
  configuration is not applied; the admin panel keeps the old hash

### Risks

- The feedback contact may report latch position rather than door position.
- Contact bounce around the unlock response can produce a false `opened`,
  because the response is a single unfiltered reading.
- A wrong `feedback_type` inverts both the unlock observation and polling.
- Several boards at 9600 baud may not fit the 500 ms poll interval.
- Protocol behaviour is tested against vendor documentation and one captured
  frame only.

## Rollout / Migration

The contract is unchanged, so the client ships before the backend.

1. Build the client with this change. Test installations run it; no `client-v*`
   tag is cut until step 2 passes.
2. Validate the reference build on hardware:
   - momentary unlock actuation with no energised output after a restart;
   - the 25 ms quiet time on the USB-RS485 adapter;
   - feedback semantics (door or latch), polarity, and contact bounce;
   - total current, lock inrush, and simultaneous actuation limits;
   - flyback, short-circuit, overcurrent, reverse-polarity, ESD, and surge
     protection;
   - RS485 termination, biasing, grounding, and daisy-chain wiring;
   - whether a DIP address change needs a power cycle;
   - protection of the full-open button;
   - two DIP-addressed boards on one bus, with a measured poll cycle;
   - USB-RS485 disconnect and reconnect;
   - a sustained polling and unlock soak test on the production Raspberry Pi.
3. Cut a `client-v*` tag for production, together with the updated README,
   hardware integration and commissioning documentation, English and German
   BOMs, and website documentation.
4. Waveshare installations stay on the last Waveshare-capable `client-v*` tag
   until their hardware is replaced.
5. Remove `waveshare_modbus` from the backend admin and validation, and migrate
   remaining rows.

## Supersedes / Superseded By

- Supersedes: ADR-0004 (its deduplication rule remains)
- Supersedes: ADR-0006
- Partially supersedes: ADR-0061 (the `waveshare_modbus` value, the
  acknowledgement-only unlock response, and the shared `modbus:` YAML key)
- Partially supersedes: ADR-0051 (the cooldown is no longer
  operator-configurable; reconnect triggers and `unreachable` are unchanged)
- Narrows: ADR-0063 (`0063-prioritize-compartment-unlock-actuation.md`),
  ADR-0040, ADR-0007, ADR-0038, and ADR-0035
- Superseded by: none

## References

- `https://github.com/Open-Locker/Open-Locker/issues/307`
- `https://github.com/Open-Locker/Open-Locker/issues/302`
- `docs/adr/0002-mqtt-message-id-and-transaction-id-separation.md`
- `docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`
- `docs/adr/0006-best-effort-startup-failsafe-for-unreachable-modbus-boards.md`
- `docs/adr/0007-aggregate-state-polling-across-all-configured-modbus-boards.md`
- `docs/adr/0035-enforce-modbus-rtu-inter-frame-delay.md`
- `docs/adr/0038-batched-door-polling-and-change-only-snapshots.md`
- `docs/adr/0040-separate-command-acknowledgement-from-door-open-detection.md`
- `docs/adr/0051-modbus-reconnect-declares-an-unreachable-bus.md`
- `docs/adr/0061-backend-managed-rs485-locker-board-profile.md`
- `docs/adr/0063-prioritize-compartment-unlock-actuation.md`
- RS485 board documentation: `https://de.aliexpress.com/item/1005004825186472.html`
