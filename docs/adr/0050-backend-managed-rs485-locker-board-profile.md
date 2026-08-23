# ADR-0050: Manage locker-board hardware profiles in the backend

## Status

Accepted

## Date

2026-08-23

## Context

Open-Locker currently configures Waveshare Modbus relay boards through a
server-managed compartment mapping. A second adapter is needed for proprietary
RS485 locker boards with 8, 12, 18, 24, 36, or 50 channels.

The RS485 boards do not expose the Waveshare Modbus register contract. They use
vendor-specific binary frames, commonly written as hexadecimal byte sequences,
with an XOR checksum. Relay actuation, feedback interpretation, and polling must
therefore be selected as one coherent hardware profile instead of inferred from
individual compartment addresses.

The two adapter families also differ in pulse behavior. Waveshare boards use the
native hardware flash registers adopted by ADR-0004. The RS485 locker board uses
its proprietary lock-actuation command; the client must not emulate Waveshare
flash registers for that adapter.

The standard RS485 board firmware is polled for lock feedback. An unsolicited
`0x82` active-feedback mode is not part of the supported contract because that
behavior requires custom firmware. Different installations can also wire or
interpret the feedback polarity differently: an active input can mean either
door closing or door opening.

## Decision

The backend owns one hardware profile per locker bank and sends it in every
`apply_config` command:

- `adapter_type`: `waveshare_modbus` or `rs485_lock_board`
- `channel_count`: `8`, `12`, `18`, `24`, `36`, or `50`
- `feedback_type`: `door_closing` or `door_opening`

These fields are required in the MQTT contract and are stored on
`locker_banks`. Existing banks receive the compatibility defaults
`waveshare_modbus`, `8`, and `door_closing`. The existing compartment mapping
continues to use `slaveId` and zero-based `address`; no board table is added.
Every address must be less than the bank's `channel_count`.
The only verified Waveshare profile remains the 8-channel Relay (D), so
`waveshare_modbus` requires `channel_count: 8`; the larger variants apply only
to `rs485_lock_board`.

The locker client selects an adapter from `adapter_type`:

- `waveshare_modbus` retains the Modbus and native hardware-flash behavior from
  ADR-0004.
- `rs485_lock_board` uses the proprietary hexadecimal frame protocol and XOR
  checksum, sends the board-specific lock command, and polls the standard
  firmware for feedback. It does not rely on unsolicited `0x82` active
  feedback.

`feedback_type` defines the lock-feedback polarity at the hardware boundary.
The adapter converts that electrical/protocol signal into the common
`open|closed|unknown` door-state model before publishing MQTT snapshots.

The client serializes inbound commands and adapter lifecycle changes so an
`apply_config` cannot switch protocol or remap a target during an unlock.
Background door detection stops without publishing an outcome if that target is
remapped after the command acknowledgement.

`config_hash` is SHA-256 over a compact UTF-8 JSON object containing exactly
`adapter_type`, `channel_count`, `feedback_type`, and `compartments`, in that key
order. Compartments are sorted ascending by `compartment_number`; each object
retains the key order `compartment_number`, `slaveId`, `address`. No extra
whitespace is emitted. `heartbeat_interval_seconds` remains outside the hash.
Backend and locker client must use this identical serialization.

## Rationale

A bank-wide profile keeps protocol selection at the same server-managed boundary
as compartment addressing while leaving adapter-specific bytes and electrical
semantics inside the locker client. Explicit channel count and polarity avoid
unsafe inference, and including them in the hash ensures an acknowledgement
refers to the complete hardware configuration.

## Alternatives Considered

### Keep the hardware profile only in locker-client configuration

- Pros:
  - no MQTT contract or backend schema change
- Cons:
  - deployment files can drift from the backend compartment mapping
  - the backend cannot validate addresses against the physical channel count
  - replacing hardware requires out-of-band client configuration
- Why not chosen:
  - the existing architecture already makes `apply_config` the source of truth
    for runtime hardware mapping

### Infer adapter and board size from compartment mappings

- Pros:
  - fewer explicit fields
- Cons:
  - protocol and feedback polarity cannot be inferred reliably
  - sparse mappings do not identify the physical board size
- Why not chosen:
  - implicit inference is ambiguous and unsafe at the hardware boundary

### Use unsolicited `0x82` active feedback

- Pros:
  - feedback could arrive without periodic polling
- Cons:
  - requires custom board firmware
  - is not portable to standard deployed boards
- Why not chosen:
  - Open-Locker supports the standard firmware and polls it deterministically

### Add a separate board table

- Pros:
  - could model multiple heterogeneous boards within one bank
- Cons:
  - adds administration and persistence complexity not needed by the current
    bank-wide profile
- Why not chosen:
  - one profile per locker bank satisfies the supported deployment model

## Consequences

### Positive

- hardware selection, channel limits, and feedback polarity are explicit and
  centrally managed
- existing Waveshare installations remain compatible through defaults
- configuration acknowledgements detect changes to the full hardware profile
- both adapters project feedback into the same door-state contract

### Negative

- older locker clients cannot accept the new required `apply_config` fields
- one locker bank cannot mix adapter families or channel counts
- backend and client serialization order is part of the hash contract

### Risks

- a partial backend/client rollout can reject commands or leave configuration
  unacknowledged
- an incorrect `feedback_type` inverts reported open and closed states
- undocumented vendor protocol variants may differ from the supported XOR
  framing or polling behavior

## Rollout / Migration

1. Add the three defaulted columns and expose them in locker-bank
   administration.
2. Deploy backend publishing and the canonical AsyncAPI contract.
3. Deploy locker-client support for both adapters and identical hash
   serialization before selecting `rs485_lock_board` for a bank.
4. Validate channel count and feedback polarity against physical hardware
   during commissioning.
5. Keep existing banks on the Waveshare defaults until explicitly migrated.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

ADR-0004 remains authoritative for Waveshare hardware flash behavior. This ADR
extends the supported hardware boundary without rewriting that decision.

## References

- Related docs:
  - `docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`
  - `docs/adr/0010-direct-slaveid-modbus-addressing.md`
  - `docs/asyncapi/schemas/payloads/command-apply-config.json`
  - `docs/mqtt_integration_plan.md`
