# Serial Locker Bus Configuration

The locker client uses one shared serial port. The external YAML key remains
`modbus` for compatibility, but the validated serial parameters are used for
both Waveshare Modbus RTU and the proprietary RS485 lock-board protocol.

## Base configuration

The local base config only contains serial/bootstrap settings. It does not select
an adapter or feedback wiring:

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

`flashDurationMs` applies only to the Waveshare adapter.

## Runtime addressing

The backend delivers compartment mapping via `apply_config`:

```json
{
  "adapter_type": "rs485_lock_board",
  "feedback_type": "door_closing",
  "compartments": [
    { "compartment_number": 1, "slaveId": 1, "address": 0 },
    { "compartment_number": 2, "slaveId": 1, "address": 1 }
  ]
}
```

Compartment `address` values are zero-based Open-Locker channel indices (0–254).
For `rs485_lock_board`, `slaveId` is the board DIP address (1–31); the wire
channel is encoded one-based as `address + 1`.

Until a runtime profile has been applied, MQTT remains online, compartment
commands are rejected, and no serial adapter is opened. A fresh installation
without a runtime overlay stays capability-neutral. An existing overlay that
contains compartments but no hardware profile is treated as legacy Waveshare
(`waveshare_modbus` + `door_closing`).

All serial operations are serialized. Both adapters enforce inter-transaction
silence derived from the configured baud rate and framing; see
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
the RTU inter-transaction delay — a wrong value there would skew bus pacing silently.

Absent fields fall back to the default; only a value that is present and
unusable is rejected.
