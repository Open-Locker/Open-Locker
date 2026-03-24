# Waveshare Modbus RTU Relay (D) Integration

This document describes the supported Open-Locker integration for the
`Waveshare Modbus RTU Relay (D)` board.

## Supported Hardware

Open-Locker officially supports only relay boards whose vendor protocol
provides a native timed `flash on/off` command that has been verified against
our implementation.

Currently supported and used board:

- `Waveshare Modbus RTU Relay (D)`

Not supported by default:

- boards without documented native `flash on/off` support
- boards that only expose generic `ON/OFF` coil writes
- boards from the same vendor family that have not been protocol-checked and
  verified in practice

This restriction exists because locker release pulses must be executed by the
board itself, not by an application-level timer in the Node.js process.

## Hardware Overview

- Model: `Waveshare Modbus RTU Relay (D)`
- Relay channels: `8`
- Digital inputs: `8`
- Communication: `RS485 / Modbus RTU`
- Power: `DC 7-36V`

## Why Hardware Flash Is Required

Previous software-timed relay control relied on:

1. send relay `ON`
2. wait in the client
3. send relay `OFF`

That is not reliable enough for locker release operations because a restart,
crash, or runtime pause can happen between both writes.

Open-Locker therefore uses the board's native timed flash registers so the
relay board performs the pulse autonomously after receiving a single command.

## Modbus Registers Used

### Read Operations

| Address | Meaning | Function Code |
| --- | --- | --- |
| `0x0000..0x0007` | Relay states | `0x01` |
| `0x0000..0x0007` | Digital input states | `0x02` |

### Write Operations

| Address | Meaning | Function Code |
| --- | --- | --- |
| `0x0000..0x0007` | Direct relay on/off/toggle | `0x05` |
| `0x00FF` | All relays on/off/toggle | `0x05` |
| `0x0200..0x0207` | Relay flash on, delay = value * `100ms` | `0x05` |
| `0x0400..0x0407` | Relay flash off, delay = value * `100ms` | `0x05` |

### Control Modes

The board also documents per-channel control modes at `0x1000..0x1007`.
Open-Locker does not use these modes for compartment release pulses.

In particular, `toggle` mode is not a replacement for `flash on/off`.

## Runtime Strategy

### Compartment Opening

- compartment numbers stay user-facing `1..8`
- relay addresses stay protocol-facing `0..7`
- opening a compartment sends a native `flash on` command to
  `0x0200 + relayAddress`
- pulse duration is configured in milliseconds and converted to Waveshare
  steps of `100ms`
- duplicate MQTT commands are deduplicated so the hardware pulse only runs once
  per transaction

### Startup Failsafe

Immediately after Modbus connection is established, the client sends
`all relays off` to every configured board before polling starts.

This is the recovery step that protects against uncertain relay state after
power loss or a process interruption.

## MQTT Behavior

The client publishes MQTT payloads with a technical `message_id`.

Relevant outbound topics:

- `locker/{uuid}/response`
- `locker/{uuid}/state`
- `locker/{uuid}/status`
- `locker/register/{token}`

Command execution is deduplicated by:

- `message_id` for packet-level duplicates
- `transaction_id` for command-level idempotency

## Configuration

Example `locker-config.yml`:

```yaml
mqtt:
  brokerUrl: mqtt://open-locker.cloud
  defaultUsername: provisioning_client
  defaultPassword: a_public_password
  heartbeatInterval: 15

modbus:
  port: /dev/ttyACM0
  flashDurationMs: 200
  clients:
    - id: locker1
      slaveId: 1
    - id: locker2
      slaveId: 2
```

Notes:

- `flashDurationMs` is converted to Waveshare units of `100ms`
- if the configured duration is not aligned to `100ms`, the client rounds up
- all configured clients share `modbus.port`

## Command Flow

```text
1. MQTT command arrives on locker/{uuid}/command
2. Client validates message_id + transaction_id
3. Client deduplicates duplicate deliveries
4. Client resolves compartment -> slave ID + relay address
5. Client sends Waveshare flash-on command to 0x0200 + relay address
6. Board performs the pulse autonomously
7. Client monitors relay state and publishes MQTT updates
8. Client sends one command response with message_id
```

## Manual Verification

### Trigger a Command

```bash
mosquitto_pub -h "open-locker.cloud" -p 1883 \
  -u "locker-uuid" -P "password" \
  -t "locker/locker-uuid/command" \
  -m '{"message_id":"11111111-1111-1111-1111-111111111111","action":"open_compartment","transaction_id":"22222222-2222-2222-2222-222222222222","timestamp":"2026-03-24T10:00:00Z","data":{"compartment_number":1}}'
```

### Observe State

```bash
mosquitto_sub -h "open-locker.cloud" -p 1883 \
  -u "locker-uuid" -P "password" \
  -t "locker/locker-uuid/state" \
  -v
```

## References

- [Waveshare Product Page](https://www.waveshare.com/modbus-rtu-relay-d.htm?srsltid=AfmBOooao9WqqByyDaeQ0hV3OQMrqtI9gXlNco-10HGkZaBKT25QI4M3)
- [Waveshare Wiki](https://www.waveshare.com/wiki/Modbus_RTU_Relay_(D)?srsltid=AfmBOoo4U9A_pYXynyHrSO7DoRWjOVUc0CliYItp1D6Aace-yA7zOzkd)
- `docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`
