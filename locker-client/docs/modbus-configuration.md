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
    { "id": 1, "slaveId": 1, "address": 0 },
    { "id": 2, "slaveId": 1, "address": 1 }
  ]
}
```

The client uses these `slaveId` values directly for command execution and state
polling.

## Legacy fallback

If no runtime compartment mapping exists yet, the client keeps a single-board
fallback and assumes `slaveId = 1`.

## Usage in code

```typescript
import { modbusService } from "./services/modbusService";

const coils = await modbusService.readCoils(0, 1, 1);
await modbusService.writeCoil(0, true, 1);
```

## Base config fields


| Field             | Required | Default | Description                            |
| ----------------- | -------- | ------- | -------------------------------------- |
| `port`            | Yes      | -       | Serial port path (e.g. `/dev/ttyACM0`) |
| `baudRate`        | No       | 9600    | Serial baud rate                       |
| `dataBits`        | No       | 8       | Data bits (7 or 8)                     |
| `stopBits`        | No       | 1       | Stop bits (1 or 2)                     |
| `parity`          | No       | `none`  | Parity (`none`, `even`, or `odd`)      |
| `timeout`         | No       | 1000    | Response timeout in milliseconds       |
| `flashDurationMs` | No       | 200     | Relay flash duration in milliseconds   |


