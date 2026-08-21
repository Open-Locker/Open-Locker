# Modbus Configuration

The locker client now uses one shared Modbus RTU bus configuration plus direct
`slaveId` addressing from the runtime compartment mapping.

## Base configuration

The local base config only contains bus-wide serial settings:

```yaml
modbus:
  port: /dev/ttyACM0
  baudRate: 9600
  dataBits: 8
  stopBits: 1
  parity: none
  timeout: 1000
  flashDurationMs: 200
```

## Runtime addressing

The backend delivers compartment mapping via `apply_config`:

```json
{
  "compartments": [
    { "compartment_number": 1, "slaveId": 1, "address": 0 },
    { "compartment_number": 2, "slaveId": 1, "address": 1 }
  ]
}
```

The client uses these `slaveId` values directly for command execution and state
polling.

Until a runtime mapping has been applied, compartment commands are rejected and
state snapshots remain empty. There is no implicit single-board fallback.

All Modbus operations are serialized on the shared RTU bus. The driver enforces
the required inter-frame silence from the configured serial parameters; see
[ADR-0035](../../docs/adr/0035-enforce-modbus-rtu-inter-frame-delay.md).

## Base config fields

| Field             | Required | Default | Accepted            | Description                            |
| ----------------- | -------- | ------- | ------------------- | -------------------------------------- |
| `port`            | Yes      | -       | non-empty           | Serial port path (e.g. `/dev/ttyACM0`) |
| `baudRate`        | No       | 9600    | 1200 – 921600       | Serial baud rate                       |
| `dataBits`        | No       | 8       | 7 or 8              | Data bits                              |
| `stopBits`        | No       | 1       | 1 or 2              | Stop bits                              |
| `parity`          | No       | `none`  | `none`/`even`/`odd` | Parity                                 |
| `timeout`         | No       | 1000    | 50 – 60000 ms       | Response timeout in milliseconds       |
| `flashDurationMs` | No       | 200     | 100 – 500 ms        | Relay flash duration in milliseconds   |

A value outside its accepted range **fails at startup** rather than being
clamped or ignored, so a mistake surfaces at the moment of deployment instead of
becoming odd behaviour hours later. `baudRate` is bounded because it also drives
the RTU inter-frame delay — a wrong value there would skew bus pacing silently.

Absent fields fall back to the default; only a value that is present and
unusable is rejected.
