# Modbus CLI - Waveshare Modbus RTU Relay Configuration Tool

A Docker-based CLI tool for configuring Waveshare Modbus RTU Relay (D) modules via USB.

## ⚠️ Important

**Only connect ONE Modbus relay board to the host at a time when using this tool.**

## Features

- Read current device configuration (slave ID, baudrate, parity, operation modes)
- Set slave ID (device address)
- Configure operation mode per channel (Normal, Linkage, Toggle, Edge Trigger)
- Containerized for consistent environment across different hosts
- USB passthrough support for direct hardware access

## Prerequisites

- Docker and Docker Compose installed
- Waveshare Modbus RTU Relay (D) board connected via USB
- USB to RS485 adapter (if needed)

## Quick Start

### 1. Build the Docker Image

```bash
cd utils/modbus-cli
docker-compose build
```

### 2. Identify Your USB Device

Find your USB serial device:

```bash
ls -l /dev/ttyUSB*
# or
dmesg | grep tty
```

If your device is not `/dev/ttyACM0`, update the `docker-compose.yml` file accordingly.

### 3. Usage Examples

#### Read Current Configuration

```bash
docker-compose run --rm modbus-cli config
```

With custom port:

```bash
docker-compose run --rm modbus-cli config -p /dev/ttyUSB1
```

#### Set Slave ID

Set the device address to 5:

```bash
docker-compose run --rm modbus-cli set-slave-id 5
```

**Note:** You must power cycle the device after changing the slave ID.

#### Set Channel Operation Mode

Set channel 1 to Linkage mode:

```bash
docker-compose run --rm modbus-cli set-mode 1 linkage
```

Set channel 3 to Toggle mode (with custom slave ID):

```bash
docker-compose run --rm modbus-cli set-mode 3 toggle -s 5
```

Available modes:
- `normal` - Relay controlled by commands only
- `linkage` - Relay status matches input channel status
- `toggle` - Relay toggles on input pulse
- `edge` - Relay changes on input level change

## Commands Reference

### `config`

Read and display the current device configuration.

**Options:**
- `-p, --port <port>` - Serial port (default: `/dev/ttyACM0`)
- `-b, --baudrate <baudrate>` - Baudrate (default: `9600`)

**Example:**
```bash
docker-compose run --rm modbus-cli config
```

### `set-slave-id <id>`

Set the slave ID (device address).

**Arguments:**
- `<id>` - New slave ID (1-255)

**Options:**
- `-p, --port <port>` - Serial port (default: `/dev/ttyACM0`)
- `-b, --baudrate <baudrate>` - Baudrate (default: `9600`)

**Example:**
```bash
docker-compose run --rm modbus-cli set-slave-id 10
```

### `set-mode <channel> <mode>`

Set the operation mode for a specific channel.

**Arguments:**
- `<channel>` - Channel number (1-8)
- `<mode>` - Operation mode: `normal`, `linkage`, `toggle`, `edge`

**Options:**
- `-p, --port <port>` - Serial port (default: `/dev/ttyACM0`)
- `-b, --baudrate <baudrate>` - Baudrate (default: `9600`)
- `-s, --slave-id <id>` - Slave ID of the device (default: `1`)

**Example:**
```bash
docker-compose run --rm modbus-cli set-mode 2 toggle -s 1
```

## Operation Modes Explained

| Mode | Description |
|------|-------------|
| **Normal** | Relay is directly controlled by Modbus commands only |
| **Linkage** | Relay status automatically matches the corresponding input channel status |
| **Toggle** | Relay toggles once when the input channel receives a pulse |
| **Edge Trigger** | Relay status changes once when the input channel level changes |

## Troubleshooting

### Permission Denied Error

If you get a permission error accessing the serial device:

```bash
# Add your user to the dialout group
sudo usermod -a -G dialout $USER

# Or run with sudo (less secure)
sudo docker-compose run --rm modbus-cli config
```

### Device Not Found

Ensure your USB device is properly connected:

```bash
ls -l /dev/ttyUSB*
```

Update `docker-compose.yml` if your device has a different name.

### No Response from Device

- Verify the device is powered
- Check that only ONE device is connected
- Verify the baudrate matches your device (default: 9600)
- Try the broadcast address by setting slave-id to 0

## Hardware Documentation

For detailed hardware specifications and Modbus protocol information, see:
- [Waveshare Modbus RTU Relay (D) Wiki](https://www.waveshare.com/wiki/Modbus_RTU_Relay_(D))

## Technical Details

- **Communication:** Modbus RTU over RS485
- **Default Settings:** 9600 baud, 8 data bits, no parity, 1 stop bit
- **Slave Address Range:** 1-255
- **Channels:** 8 relay channels with individual mode control

## Development

### Running Without Docker

Install dependencies:

```bash
npm install
```

Build:

```bash
npm run build
```

Run:

```bash
node dist/index.js config
```

### Development Mode

```bash
npm run dev -- config
```

## License

MIT
