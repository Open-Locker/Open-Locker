# ADR-0004: Use Waveshare hardware flash commands for relay pulses

## Status

Accepted

## Date

2026-03-24

## Context

The `locker-client` currently opens compartments by writing a relay coil `ON`
and scheduling a later `OFF` write in the Node.js process.

This software-timed approach is not reliable enough for locker release
operations:

- a client crash or restart between `ON` and `OFF` can leave a relay energized
- startup previously did not actively reset all relays to `OFF`
- MQTT QoS 1 can redeliver commands, so command execution must be idempotent
- the Waveshare Modbus RTU Relay (D) board provides native timed relay commands
  that are more deterministic than an application-level timer

The official Waveshare protocol documents dedicated relay flash commands:

- `0x0200..0x0207`: relay flash on
- `0x0400..0x0407`: relay flash off
- `0x00FF` with `0x0000`: all relays off

This change affects the Modbus communication strategy, startup safety behavior,
and the documented hardware support boundary.

## Decision

Open-Locker uses the Waveshare board's native hardware flash command for relay
release pulses instead of a software-managed `ON` followed by delayed `OFF`.

We also adopt the following rules:

- on client startup, every configured Modbus board is explicitly commanded to
  turn all relays `OFF` before normal polling starts
- client-side MQTT command handling must deduplicate by `message_id` and
  `transaction_id` so duplicate deliveries do not trigger a second hardware
  pulse
- documentation must explicitly state that only boards with verified native
  `flash on/off` support are supported for this relay strategy
- the currently documented and verified target board is `Waveshare Modbus RTU
  Relay (D)`

## Rationale

Using the hardware-timed flash command moves pulse timing to the relay board,
which is closer to the hardware and less vulnerable to process scheduling,
runtime pauses, or crashes.

The startup `all relays off` command adds a deterministic recovery step after
restarts or power interruptions.

Restricting official support to verified boards avoids claiming compatibility
for other boards that may differ in register layout or timed relay behavior.

## Alternatives Considered

### Alternative A: Keep software-timed relay pulses in the client

- Pros:
  - no protocol changes in the Modbus layer
  - works with generic on/off coil control
- Cons:
  - client crash can leave relay state uncertain
  - less deterministic timing
  - weaker startup safety story
- Why not chosen:
  - does not meet the reliability goal of the issue

### Alternative B: Support all Waveshare relay boards by family name

- Pros:
  - broader compatibility claim
  - less documentation work up front
- Cons:
  - risks undocumented protocol differences
  - weakens reliability guarantees
- Why not chosen:
  - support claims should be based on verified protocol behavior, not brand
    similarity

## Consequences

### Positive

- relay release timing is handled by the board itself
- startup behavior becomes safer and more deterministic
- duplicate command deliveries are less likely to produce repeated pulses
- documentation becomes clearer about supported hardware

### Negative

- the client now depends on Waveshare-specific relay flash registers
- unsupported boards may require a different implementation strategy
- command dedup state must be persisted locally

### Risks

- partial rollout can create temporary contract mismatches for MQTT payloads
- stale dedup records can suppress retried commands until they expire

Mitigations:

- add `message_id` to outbound backend command payloads
- keep dedup records time-bounded
- document the supported-board policy explicitly

## Rollout / Migration

- update the client Modbus layer to use native flash registers
- add startup `all relays off` before polling
- add client-side MQTT dedup for command execution
- update backend outbound command payloads to include `message_id`
- update hardware and integration docs to reflect the supported-board boundary

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues:
  - `https://github.com/Open-Locker/Open-Locker/issues/61`
  - `https://github.com/Open-Locker/Open-Locker/issues/42`
  - `https://github.com/Open-Locker/Open-Locker/issues/41`
- Related docs:
  - `docs/adr/0002-mqtt-message-id-and-transaction-id-separation.md`
  - `locker-client/docs/WAVESHARE_INTEGRATION.md`
  - `https://www.waveshare.com/modbus-rtu-relay-d.htm?srsltid=AfmBOooao9WqqByyDaeQ0hV3OQMrqtI9gXlNco-10HGkZaBKT25QI4M3`
  - `https://www.waveshare.com/wiki/Modbus_RTU_Relay_(D)?srsltid=AfmBOoo4U9A_pYXynyHrSO7DoRWjOVUc0CliYItp1D6Aace-yA7zOzkd`
