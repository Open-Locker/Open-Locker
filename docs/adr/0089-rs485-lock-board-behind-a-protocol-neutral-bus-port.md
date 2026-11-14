# ADR-0089: RS485 lock board behind a protocol-neutral locker bus port

## Status

Accepted

## Date

2026-11-14

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

### 3. The unlock response decides the door outcome

The board reads its lock feedback input about 250 to 500 ms after the unlock and
returns that reading in the unlock response. The vendor documentation calls the
two values "unlock succeeded" and "unlock failed"; they are the same feedback
reading that query-all reports. The RS485 adapter maps the response through the
bank's `feedback_type`, the same polarity used for query-all:

- `door_closing`: `00` = `open`, `11` = `closed`
- `door_opening`: `11` = `open`, `00` = `closed`

When the board answered, the command response is `success`, and
`OpenCompartmentUseCase` publishes the outcome right after it, without a
detection window:

| Unlock response | Command response | Door event |
|---|---|---|
| `open` | `success` | `opened` |
| `closed` | `success` | `door_jammed` (`DOOR_JAMMED`) |
| no usable response | `HARDWARE_ERROR` (section 4) | `opened` if the door is later observed open; otherwise none |

A `closed` response is a physical observation, not a transport failure, so it is
reported on the event channel as ADR-0040 requires, and the backend shows it as
"did not open" and alerts the locker manager. `HARDWARE_ERROR` stays reserved for
the case where the client does not know whether the lock released. The bus stays
connected after a `closed` response. The actuation is still recorded, so a door
that opens shortly afterwards is not reported as uncommanded.

A controller that reports no door state with its unlock (no observation or
`unknown`) keeps the query-all detection window from ADR-0040.

Command acknowledgement and door events stay separate messages (ADR-0040);
whether to merge them is evaluated in
[#394](https://github.com/Open-Locker/Open-Locker/issues/394). The client still actuates when the
door already reads `open` (#302); the unlock response then reads `open` and
`opened` follows about 500 ms after the command.

The ESP32 firmware speaks the same MQTT contract (ADR-0072) and adopts the same
mapping: today it answers a failure status with `HARDWARE_ERROR` (ADR-0071) and
moves to `success` plus `door_jammed` in a separate change.

Query-all polling continues to detect manual and uncommanded openings.

### 4. An unlock is never re-sent automatically

Reconnect behaviour is unchanged: timeouts, BCC mismatches, and port errors stay
reconnectable, and ADR-0051 decides when the bus is `unreachable`. After an
`UnlockOutcomeUnknownError`, however, the reconnect does not re-run the unlock,
and the caller still receives that error even when the reconnect fails. Only
`UnlockNotSentError` may be retried after reconnecting.

Both errors fail the `open_compartment` command with a hardware error. After
`UnlockOutcomeUnknownError`, `OpenCompartmentUseCase` records the attempt as a
possible actuation and starts a detection window that only reports `opened` if
the door is later observed open. It never reports `door_jammed`: the command
already failed, and a jam alert would misread a bus fault (the usual cause of a
lost reply) as a stuck lock. `UnlockNotSentError` starts no detection.

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
- request gap: 50 ms from the end of one transaction to the next request.
  The boards ignore a request that follows the previous reply on the bus too
  closely: on a locker with three boards on one bus, a request about 10 ms
  after the reply went unanswered every time, while a later one was answered
  at once ([#350](https://github.com/Open-Locker/Open-Locker/issues/350)).
  A transaction ends 25 ms after its last byte, so the line is quiet for about
  75 ms before each request. The Modbus RTU gap from ADR-0035 does not apply
  to this protocol;
- reconnect budget and cooldown (ADR-0051).

The external YAML key becomes `serial:` with a single `port` entry, preferably a
stable `/dev/serial/by-id/...` path. `modbus:` remains a deprecated alias whose
`port` is read; its other keys are ignored with a warning.

### 7. Protocol handling

- A received burst is split into messages by their known formats, as in the
  firmware (ADR-0071): `8A` and `82` are five bytes; for `80`, every length that
  ends with the `33` command marker and a matching XOR is a candidate, and the
  longest wins. A shorter prefix can also be BCC-valid when a status byte is
  `0x33` (ADR-0071 Risks). Bytes that start no valid message are skipped. The
  board can send a `82` door event right behind a reply with no gap, so a burst
  may hold a reply and an event.
- XOR/BCC is validated over every message.
- Query-all response length comes from the received frame, never from a
  declared channel count; only configured channels are selected.
- Missing status bits produce `unknown`.
- `slaveId` is the physical DIP address, `1..31`.
- Zero-based compartment addresses become one-based wire channels inside the
  adapter.
- Polling uses query-all. Unsolicited `0x82` door events are ignored, since the
  periodic poll already reports doors.
- Full-open and multi-channel unlock commands are not exposed.

### 8. Transaction ordering and isolation

- Serial transactions run one at a time in priority order, unlocks before
  polling, and in submission order within a priority.
  `RuntimeConfiguredLockerBus` keeps its queue for adapter swaps but passes
  each operation's priority through.
- After a timeout, a BCC mismatch, or unexpected bytes, the adapter waits for
  the frame quiet time and discards all buffered input before the next request.
- An exchange takes the message that answers its request: for an unlock, the
  `8A` reply with the request's board address, channel, and a `00` or `11`
  status; for query-all, the `80` response with the request's board address. A
  burst without such a message is rejected.
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

With a reply after about 20 ms, the 25 ms quiet time, and the 50 ms request
gap, one board takes about 95 ms per poll. Three boards fit the 500 ms interval
at about 0.3 s, and an unlock waits at most one transaction behind a poll.

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

### Report a `closed` unlock response as `HARDWARE_ERROR` (ADR-0071)

- Pros: the command fails at once; matches the firmware as implemented
- Cons: a physical outcome is reported as a transport failure; the backend
  records a generic `Failed` instead of "did not open" and alerts no one, and
  the same code then means both "lock stayed shut" and "outcome unknown"
- Why not chosen: ADR-0040 reports physical outcomes on the event channel, and
  `door_jammed` already carries the right meaning and alert

### Keep the detection window after a `closed` unlock response

- Pros: a lock that releases late still ends as `opened`
- Cons: a lock that is really blocked takes the full window (about 10 s) to
  report
- Why not chosen: the response is read after the lock has had its release
  time; a late release stays attributed through the actuation record

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
- `opened` and `door_jammed` are reported directly from the unlock response,
  without a detection window
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
- Contact bounce around the unlock response can produce a false `opened`, and
  a lock that releases after the board's reading a false `door_jammed`, because
  the response is a single unfiltered reading.
- A door event that arrives alone, more than the frame quiet time before the
  reply, completes the burst without a reply: an unlock then ends as an unknown
  outcome, a poll as `unknown` for one cycle. The firmware behaves the same.
- Until the firmware change ships, a refused unlock is `success` plus
  `door_jammed` from a Pi and `HARDWARE_ERROR` from an ESP32.
- A wrong `feedback_type` inverts both the unlock observation and polling.
- Several boards at 9600 baud may not fit the 500 ms poll interval.
- The 50 ms request gap is derived from one failing and one working gap, not
  from a measured threshold. If it is too short, a silent board is no longer
  queried again within the same poll, so its compartments read `unknown` on
  every poll instead of only arriving late.
- Protocol behaviour is tested against vendor documentation and one captured
  frame only.

## Rollout / Migration

The contract is unchanged, so the client ships before the backend.

1. Build the client with this change. Test installations run it; no `client-v*`
   tag is cut until step 2 passes.
2. Validate the reference build on hardware:
   - momentary unlock actuation with no energised output after a restart;
   - the 25 ms quiet time on the USB-RS485 adapter;
   - the 50 ms request gap on the Hameln locker (`locker-3`, three boards on
     one bus): no unanswered query in a serial trace, and the time from
     receiving an open command to its response in the command dedup store,
     repeated as in #350;
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

## Confirmation

- Unlock never re-sent after an unknown outcome:
  `RS485 actor reconnects after an unlock with an unknown outcome but never re-sends it`;
  re-sent when nothing left the client:
  `RS485 actor reconnects and re-sends an unlock that never left the client`.
- Unknown unlock outcome:
  `watches for opened after an unknown unlock outcome but never reports door_jammed`,
  `ends the unknown-outcome watch without door_jammed when the door stays shut`.
- Unlock priority end to end:
  `an unlock overtakes door reads already queued behind a running operation`.
- Unlock response mapping:
  `unlock response maps door_closing feedback: 00 is open, 11 is closed`,
  `unlock response maps door_opening feedback: 11 is open, 00 is closed`,
  `reports opened straight from an unlock response that observed the door open`,
  `reports door_jammed straight from an unlock response that observed the door closed`,
  `keeps polling when the unlock response observed no door state`.
- Detection window: `a slow unlock does not use up the detection window`.
- Bursts split into messages (ports of firmware tests S1-H32, S1-H33, S1-H35):
  `an unlock reply followed by a door event is read`,
  `a query-all response followed by a door event is read`,
  `a door event and noise ahead of the reply are skipped`,
  `a bus that only echoes requests never reports an unlock as opened`,
  `a query-all reply prefers the longest valid frame over a shorter BCC-valid prefix`.
- Late responses discarded:
  `a late response after a timeout is discarded, not read as the next answer`.
- Silent board not queried again:
  `RS485 actor reconnects after a silent board but does not query it again`.
- Request gap:
  `transport keeps a 50 ms gap between the end of a transaction and the next request`.
- On hardware: the checks in Rollout step 2.

## Supersedes / Superseded By

- Supersedes: ADR-0004 (its deduplication rule remains)
- Supersedes: ADR-0006
- Partially supersedes: ADR-0035 (the RS485 adapter uses its own 50 ms request
  gap instead of the Modbus RTU inter-frame delay)
- Partially supersedes: ADR-0061 (the `waveshare_modbus` value, the
  acknowledgement-only unlock response, and the shared `modbus:` YAML key)
- Partially supersedes: ADR-0051 (the cooldown is no longer
  operator-configurable; reconnect triggers and `unreachable` are unchanged)
- Partially supersedes: ADR-0071 (a failure status in the unlock response is
  reported as `door_jammed` after a `success` response instead of
  `HARDWARE_ERROR`; its reply framing decision is unchanged)
- Narrows: ADR-0063 (`0063-prioritize-compartment-unlock-actuation.md`),
  ADR-0040, ADR-0007, and ADR-0038
- Superseded by: none

## References

- `https://github.com/Open-Locker/Open-Locker/issues/307`
- `https://github.com/Open-Locker/Open-Locker/issues/302`
- `https://github.com/Open-Locker/Open-Locker/issues/350`
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
- `docs/adr/0071-rs485-lock-panel-replies-with-active-feedback.md`
- `docs/adr/0072-esp32-locker-firmware.md`
- RS485 board documentation: `https://de.aliexpress.com/item/1005004825186472.html`
