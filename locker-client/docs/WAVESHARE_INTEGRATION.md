# Waveshare Modbus RTU Relay (D) Integration

This document describes the integration with the Waveshare Modbus RTU Relay (D) 8-channel relay board for locker compartment control.

## Hardware Overview

**Model:** Waveshare Modbus RTU Relay (D)
**Channels:** 8 relay outputs + 8 digital inputs
**Communication:** RS485 (Modbus RTU protocol)
**Power:** DC 7-36V

## Hardware Features

### Relay Outputs (8 channels)
- **Contact Form:** 1NO (Normally Open) + 1NC (Normally Closed)
- **Contact Load:** ≤10A 250V AC or ≤10A 30V DC
- **Purpose:** Control electric locks on compartments
- **Addresses:** 0x0000 - 0x0007 (channels 1-8)

### Digital Inputs (8 channels)
- **Input Type:** 5-36V, passive/active (NPN or PNP)
- **Purpose:** Door sensor monitoring (open/closed detection)
- **Addresses:** 0x0000 - 0x0007 (channels 1-8)

## Modbus Protocol

### Default Communication Settings
- **Baud Rate:** 9600
- **Data Bits:** 8
- **Stop Bits:** 1
- **Parity:** None
- **Slave Address:** 1-255 (configurable)

### Function Codes Used

| Code | Name | Purpose |
|------|------|---------|
| 0x01 | Read Coils | Read relay status |
| 0x02 | Read Discrete Inputs | Read door sensor status |
| 0x05 | Write Single Coil | Control single relay |
| 0x0F | Write Multiple Coils | Control multiple relays |

## Implementation

### Relay Control

**Compartment numbering:** 1-8 (user-facing)  
**Relay addressing:** 0-7 (Modbus protocol)

#### Open Compartment (Unlock)
```typescript
// Convert compartment ID (1-based) to relay address (0-based)
const relayAddress = compartmentID - 1;

// Turn relay ON to unlock (Function Code 0x05)
await modbusService.writeCoil(relayAddress, true, clientId);

// Keep unlocked for 3 seconds
setTimeout(() => {
  await modbusService.writeCoil(relayAddress, false, clientId);
}, 3000);
```

#### Read Relay Status
```typescript
// Read all 8 relay states (Function Code 0x01)
const states = await modbusService.readCoils(0x0000, 8, clientId);
// Returns: [false, false, true, false, false, false, false, false]
// Means: Relay 3 (compartment 3) is ON, others are OFF
```

### Door Sensor Monitoring

#### Read Digital Inputs
```typescript
// Read all 8 digital input states (Function Code 0x02)
const inputs = await modbusService.readDiscreteInputs(0x0000, 8, clientId);
// Returns: [true, false, false, false, false, false, false, false]
// Means: Input 1 triggered (door open), others idle (doors closed)
```

### Wiring Diagram

```
Compartment 1:
  Lock -------- NO1 (Normally Open)
  Sensor ------ DI1 (Digital Input 1)

Compartment 2:
  Lock -------- NO2
  Sensor ------ DI2

... (repeat for compartments 3-8)

RS485 Communication:
  A (RS485+) --- To USB-RS485 adapter A
  B (RS485-) --- To USB-RS485 adapter B
  
Power:
  DC+ (7-36V) --- Power supply positive
  DC- -------- Power supply negative
```

## Status Publishing

### Relay/Lock State
Published to: `locker/{uuid}/status`

```json
{
  "compartment_id": 1,
  "relay_state": "ON",
  "lock_state": "UNLOCKED",
  "timestamp": "2026-01-03T20:00:00Z"
}
```

### Combined System State
Published to: `locker/{uuid}/state` (every 2 seconds)

```json
{
  "event": "status_update",
  "data": {
    "timestamp": "2026-01-03T20:00:00Z",
    "compartments": [
      {
        "compartment_id": 1,
        "relay_state": "OFF",
        "lock_state": "LOCKED",
        "door_sensor": "IDLE",
        "door_state": "CLOSED"
      },
      {
        "compartment_id": 2,
        "relay_state": "ON",
        "lock_state": "UNLOCKED",
        "door_sensor": "TRIGGERED",
        "door_state": "OPEN"
      }
      // ... compartments 3-8
    ]
  }
}
```

## Configuration

### Environment Variables (.env)

```bash
# Modbus Configuration for Waveshare Board
MODBUS_CLIENTS=[{
  "id": "locker2",
  "port": "/dev/ttyACM0",
  "baudRate": 9600,
  "dataBits": 8,
  "stopBits": 1,
  "parity": "none",
  "slaveId": 2,
  "timeout": 1000
}]
```

### Multiple Boards
```bash
# For multiple Waveshare boards
MODBUS_CLIENTS=[
  {"id":"locker1","port":"/dev/ttyUSB0","slaveId":1},
  {"id":"locker2","port":"/dev/ttyUSB1","slaveId":2}
]
```

## Timing Parameters

| Parameter | Value | Description |
|-----------|-------|-------------|
| Unlock Duration | 3000ms | Time relay stays ON (lock release) |
| Monitoring Interval | 500ms | Frequency of relay state checks during unlock |
| Polling Interval | 2000ms | Frequency of full system status updates |

## Command Flow

```
1. MQTT Command Received
   └─> "open_compartment", compartment_number: 3

2. Convert to Relay Address
   └─> Compartment 3 → Relay Address 2 (0-based)

3. Activate Relay (Unlock)
   └─> writeCoil(2, true) → Function Code 0x05

4. Start Monitoring
   └─> Poll relay state every 500ms
   └─> Publish status to MQTT

5. Auto-Lock After 3 seconds
   └─> writeCoil(2, false)
   └─> Stop monitoring when relay OFF

6. Send MQTT Response
   └─> "result": "success"
```

## Error Handling

### Modbus Communication Errors
- **Timeout:** Relay doesn't respond → `MODBUS_ERROR`
- **Invalid Address:** Compartment > 8 → `COMPARTMENT_NOT_FOUND`
- **Connection Lost:** USB disconnected → `HARDWARE_ERROR`

### Hardware Errors
- **Lock Jammed:** Relay activates but lock doesn't release → `DOOR_JAMMED`
- **Port Busy:** `/dev/ttyACM0` already in use → App continues in MQTT-only mode

## Testing

### Manual Relay Control
```bash
# Test relay 0 (compartment 1) ON
mosquitto_pub -h "open-locker.cloud" -p 1883 \
  -u "locker-uuid" -P "password" \
  -t "locker/locker-uuid/command" \
  -m '{"action":"open_compartment","transaction_id":"test-1","timestamp":"2026-01-03T10:00:00Z","data":{"compartment_number":1}}'
```

### Check Status
```bash
# Subscribe to status updates
mosquitto_sub -h "open-locker.cloud" -p 1883 \
  -u "locker-uuid" -P "password" \
  -t "locker/locker-uuid/state" \
  -v
```

### Direct Modbus Testing (Python)
```python
from pymodbus.client import ModbusSerialClient

client = ModbusSerialClient(
    port='/dev/ttyACM0',
    baudrate=9600,
    parity='N',
    stopbits=1,
    bytesize=8,
    timeout=1
)

client.connect()

# Turn relay 0 ON
client.write_coil(0x0000, True, slave=2)

# Read relay status
result = client.read_coils(0x0000, 8, slave=2)
print(result.bits)  # [True, False, False, ...]

# Read digital inputs
inputs = client.read_discrete_inputs(0x0000, 8, slave=2)
print(inputs.bits)  # [False, False, False, ...]

client.close()
```

## Troubleshooting

### Issue: No Modbus Response
**Check:**
1. RS485 wiring (A-A, B-B connected)
2. Slave address matches config (default: 2)
3. Baud rate matches (default: 9600)
4. Port is correct (`/dev/ttyACM0` or `/dev/ttyUSB0`)
5. No other process using the port

**Test:**
```bash
# Check USB device
ls -la /dev/ttyACM* /dev/ttyUSB*

# Check permissions
sudo chmod 666 /dev/ttyACM0
```

### Issue: Relay Activates but Lock Doesn't Open
**Check:**
1. Lock wiring to NO (Normally Open) terminal
2. Lock power supply (voltage/current sufficient)
3. Lock is not mechanically jammed
4. COM terminal properly connected

### Issue: Door Sensor Not Detecting
**Check:**
1. Sensor wiring to DI terminal
2. Sensor type (passive/active, NPN/PNP)
3. COM terminal configuration
4. Sensor power supply (if active type)

## Safety Notes

⚠️ **Important Safety Information:**

1. **Electrical Safety**
   - Qualified personnel only for installation
   - Disconnect power before wiring
   - Use appropriate fuses/circuit breakers

2. **Load Matching**
   - Verify lock current ≤ 10A
   - Account for inductive load starting current
   - Use RC snubbers for inductive loads

3. **Installation**
   - Keep away from moisture/high temps
   - Secure mounting to avoid vibrations
   - Proper ventilation for heat dissipation

## References

- [Waveshare Product Page](https://www.waveshare.com/modbus-rtu-relay-d.htm)
- [Official Wiki](https://www.waveshare.com/wiki/Modbus_RTU_Relay_(D))
- [Modbus RTU Protocol](https://www.waveshare.com/wiki/Modbus_Protocol_Specification)
- [Demo Code](https://files.waveshare.com/wiki/Modbus-RTU-Relay-D/Modbus_RTU_Relay_D_Code.zip)
