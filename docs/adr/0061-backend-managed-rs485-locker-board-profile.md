# ADR-0061: Backend-managed RS485 locker board hardware profile

## Status

Accepted

## Date

2026-09-19

## Context

Open-Locker configures locker hardware through server-managed `apply_config`
commands. Waveshare Modbus relay boards and proprietary RS485 locker boards
share the same MQTT boundary but use different serial protocols, actuation
semantics, and feedback wiring.

A verified 8-channel RS485 board at DIP address 1 responds to query-all with
seven bytes despite eight physical channels:

```text
TX 80 01 00 33 B2
RX 80 01 00 00 00 33 B2
```

Response length must not be inferred from a declared board size or compartment
count. The locker client completes frames using inter-byte quiet time derived
from configured baud and framing (see ADR-0035), validates structure and XOR
after the frame is complete, and maps only configured compartment addresses to
door state. Missing status bits become `unknown`, not a crash or a different
board size.

Command acknowledgement and physical door state must stay separate (ADR-0040).
Unlock/actuation confirms that the hardware accepted the pulse; `open`,
`closed`, and `unknown` come only from door-sensor polling. The RS485 unlock
response must never directly emit `opened` or `door_jammed`. The client stops
producing new `already_open` outcomes; the backend may retain historical events.

Serial reconnect, cooldown-bounded recovery, and `unreachable` reporting follow
ADR-0051. Valid runtime configuration must persist and reload even when the
serial adapter is disconnected at apply time; only validation, hash mismatch,
persistence, or adapter-construction failures roll back the overlay.

Existing runtime overlays that contain compartments but no hardware profile
fields are treated as legacy Waveshare (`adapter_type = waveshare_modbus`,
`feedback_type = door_closing`). Fresh clients without an overlay remain
capability-neutral until the first successful `apply_config`.

## Decision

ADR-0009 remains authoritative for separating base YAML from the runtime overlay,
persistence mechanics, and merge behaviour. **This ADR is the forward authority
for the server-managed overlay field set**, which comprises `mqtt.heartbeatInterval`,
`compartments`, `adapter_type`, and `feedback_type`. It partially supersedes
ADR-0009 only for that field list.

The backend owns one hardware profile per locker bank and publishes it on every
`apply_config` command:

- `adapter_type`: `waveshare_modbus` or `rs485_lock_board`
- `feedback_type`: `door_closing` or `door_opening`

These fields are required in the MQTT contract and stored on `locker_banks`.
Existing rows default to `waveshare_modbus` and `door_closing`. There is no
`channel_count` column or payload field.

Compartment mappings continue to use `slaveId` and zero-based `address`. Multiple
boards on one serial bus are represented by different `slaveId` values; no board
inventory table is added.

Address validation enforces only wire-format constraints:

- zero-based `address` must be encodable as a non-zero one-byte wire channel
  (0 through 254 inclusive)
- for `rs485_lock_board`, `slaveId` must be within the supported DIP range
  (1 through 31)

The backend does not impose product channel counts (8, 12, 18, and so on).

`config_hash` is SHA-256 over compact UTF-8 JSON containing exactly
`adapter_type`, `feedback_type`, and `compartments`, in that key order.
Compartments are sorted ascending by `compartment_number`; each object keeps the
key order `compartment_number`, `slaveId`, `address`. No extra whitespace.
`heartbeat_interval_seconds` remains outside the hash. Backend and locker client
must use identical serialization.

The locker client selects an adapter from `adapter_type`:

- `waveshare_modbus` retains Modbus and native hardware-flash behavior (ADR-0004).
- `rs485_lock_board` uses proprietary binary frames with XOR checksum, lock
  actuation commands, and polled standard firmware feedback (no unsolicited
  `0x82` mode in the supported contract).

`feedback_type` defines lock-feedback polarity at the hardware boundary; both
adapters project into the common `open|closed|unknown` door-state model before
MQTT snapshots.

Inbound commands and adapter lifecycle changes remain serialized so
`apply_config` cannot switch protocol during an in-flight unlock. Background door
detection after actuation follows ADR-0040.

The external YAML key `modbus:` remains shared by both serial adapters in this
change set; baud, data bits, stop bits, parity, timeout, and reconnect cooldown
are read from that block until a later rename introduces a backward-compatible
alias.

## Rationale

Explicit adapter and feedback polarity at the backend boundary keeps deployment
truth aligned with compartment mapping without guessing protocol from sparse
addresses. Dropping `channel_count` matches observed hardware: frame length and
status coverage are validated after reception, not predeclared. Narrow hash input
to fields the server actually manages avoids false config drift when board size
differs from mapping size. Separating actuation acknowledgement from door
observation prevents false `opened` outcomes when feedback wiring does not
reflect bolt position.

## Alternatives Considered

### Keep `channel_count` on the bank profile

- Pros:
  - admin UI could cap compartment addresses per product SKU
- Cons:
  - verified 8-channel hardware returns a seven-byte query-all frame
  - implies response length follows declared size
  - duplicates information already carried by compartment addresses
- Why not chosen:
  - wire encodability and configured mappings are sufficient; board size is not
    reliably known from product labels alone

### Infer adapter and board size from compartment mappings alone

- Pros:
  - fewer explicit fields
- Cons:
  - protocol and feedback polarity are not inferable
  - sparse mappings do not identify physical layout
- Why not chosen:
  - unsafe at the hardware boundary

### Use unsolicited `0x82` active feedback

- Pros:
  - push-style feedback without polling
- Cons:
  - requires custom firmware; not portable to standard boards
- Why not chosen:
  - Open-Locker supports standard firmware with deterministic polling

### Add a separate board table

- Pros:
  - could model heterogeneous boards per bank explicitly
- Cons:
  - administration and persistence complexity beyond current deployments
- Why not chosen:
  - one profile per bank plus `slaveId` addressing satisfies supported sites

## Consequences

### Positive

- MQTT and admin model match verified RS485 behavior without false frame-length
  coupling
- hash acknowledgements cover the full server-managed hardware profile
- legacy Waveshare installations remain compatible through defaults and overlay
  fallback
- actuation and door state stay distinct across both adapters

### Negative

- backend and locker-client must deploy together for the new hash and payload
  shape
- older locker clients expecting `channel_count` reject new commands until upgraded
- incorrect `feedback_type` inverts reported open and closed states

### Risks

- partial rollout leaves configuration unacknowledged or mis-hashed
- undocumented vendor protocol variants may differ from supported XOR framing
- larger RS485 boards need captured frames in tests before claiming layout support

## Rollout / Migration

1. Deploy backend schema with `adapter_type` and `feedback_type` defaults only.
2. Publish the updated AsyncAPI contract and ADR-0061.
3. Deploy locker-client support for shared serial timing, quiet-period framing,
   shared reconnect policy, legacy overlay fallback, and the revised hash before
   selecting `rs485_lock_board` in production.
4. Commission RS485 banks with DIP address and feedback polarity checks against
   physical hardware.
5. Keep existing banks on Waveshare defaults until explicitly migrated.

## Supersedes / Superseded By

- Partially supersedes: ADR-0009 (runtime overlay now includes
  `adapter_type` and `feedback_type` in server-managed profile semantics)
- Narrows: ADR-0040 (locker client stops producing new `already_open`; historical
  backend handling may remain)
- Supersedes: the branch draft `docs/adr/0058-backend-managed-rs485-locker-board-profile.md`
  (not merged under number 0058 due to collision with unrelated history on `main`)
- Superseded by: none

ADR-0004 remains authoritative for Waveshare hardware flash. ADR-0035 and
ADR-0051 remain authoritative for inter-frame timing and unreachable recovery;
this ADR references them instead of restating their policies.

## References

- `docs/rs485-locker-board-pr-adjustment-plan.md`
- `docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`
- `docs/adr/0009-locker-client-runtime-config-overlay.md`
- `docs/adr/0010-direct-slaveid-modbus-addressing.md`
- `docs/adr/0035-enforce-modbus-rtu-inter-frame-delay.md`
- `docs/adr/0040-separate-command-acknowledgement-from-door-open-detection.md`
- `docs/adr/0051-modbus-reconnect-declares-an-unreachable-bus.md`
- `docs/asyncapi/schemas/payloads/command-apply-config.json`
- `docs/mqtt_integration_plan.md`
