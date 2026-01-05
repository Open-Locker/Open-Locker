# Modbus Configuration

The locker client supports connecting to multiple Modbus RTU devices simultaneously.

## Configuration Methods

### Method 1: Single Client (Simple)

Use individual environment variables for a single Modbus connection:

```env
MODBUS_PORT=/dev/ttyACM0
MODBUS_BAUD_RATE=9600
MODBUS_DATA_BITS=8
MODBUS_STOP_BITS=1
MODBUS_PARITY=none
MODBUS_SLAVE_ID=1
MODBUS_TIMEOUT=1000
```

This creates a client with ID `"default"`.

### Method 2: Multiple Clients (Advanced)

Define multiple Modbus clients using the `MODBUS_CLIENTS` environment variable with a JSON array:

```env
MODBUS_CLIENTS=[
  {
    "id": "locker1",
    "port": "/dev/ttyACM0",
    "slaveId": 1,
    "baudRate": 9600,
    "dataBits": 8,
    "stopBits": 1,
    "parity": "none",
    "timeout": 1000
  },
  {
    "id": "locker2",
    "port": "/dev/ttyACM1",
    "slaveId": 2,
    "baudRate": 9600
  }
]
```

**Note:** Each client must have a unique `id` and `port`. Optional fields will use defaults.

## Usage in Code

### Using the Default Client

```typescript
import { modbusService } from "./services/modbusService";

// Reads from the "default" client
const coils = await modbusService.readCoils(0, 1);

// Writes to the "default" client
await modbusService.writeCoil(0, true);
```

### Using a Specific Client

```typescript
import { modbusService } from "./services/modbusService";

// Read from a specific client
const coils = await modbusService.readCoils(0, 1, "locker1");

// Write to a specific client
await modbusService.writeCoil(0, true, "locker2");
```

### Getting Available Clients

```typescript
import { modbusService } from "./services/modbusService";

const clientIds = modbusService.getClientIds();
console.log("Connected clients:", clientIds);
// Output: ["locker1", "locker2"]
```

## Configuration Fields

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| `id` | Yes | - | Unique identifier for the client |
| `port` | Yes | - | Serial port path (e.g., `/dev/ttyACM0`) |
| `slaveId` | No | 1 | Modbus slave ID |
| `baudRate` | No | 9600 | Serial baud rate |
| `dataBits` | No | 8 | Data bits (7 or 8) |
| `stopBits` | No | 1 | Stop bits (1 or 2) |
| `parity` | No | "none" | Parity ("none", "even", or "odd") |
| `timeout` | No | 1000 | Response timeout in milliseconds |
