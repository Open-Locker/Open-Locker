# Locker Client

Docker-based client for managing Open Locker hardware via Modbus and MQTT.

## Quick Start

### Pull the Docker Image

```bash
docker pull ghcr.io/open-locker/locker-client:latest
```

### Run the Container

```bash
docker run -d \
  --name locker-client \
  --device=/dev/ttyACM0:/dev/ttyACM0 \
  --env-file .env \
  --restart unless-stopped \
  ghcr.io/open-locker/locker-client:latest
```

## Configuration

### Environment Variables

Create a `.env` file with the following configuration:

```env
# Modbus serial device
MODBUS_PORT=/dev/ttyACM0

# Multiple Modbus clients configuration (JSON array)
MODBUS_CLIENTS=[{"id":"locker2","port":"/dev/ttyACM0","slaveId": 2}]

# MQTT broker connection
MQTT_BROKER_URL=mqtt://open-locker.cloud
MQTT_DEFAULT_USERNAME=provisioning_client
MQTT_DEFAULT_PASSWORD=a_public_password

# Provisioning token (required for initial setup)
PROVISIONING_TOKEN=your_provisioning_token_here

# Heartbeat interval in seconds (default: 15)
HEARTBEAT_INTERVAL=15

# Optional: Enable debug logging
# LOG_LEVEL=debug
```

### Device Access

The container requires access to the serial device for Modbus communication:
- **Device**: `/dev/ttyACM0` (or your specific serial device)
- **Mount**: Use `--device` flag to grant container access

⚠️ **Note**: Ensure your serial device path matches the one in your `.env` file.

## Docker Commands

### View Logs

```bash
docker logs -f locker-client
```

### Stop Container

```bash
docker stop locker-client
```

### Remove Container

```bash
docker rm locker-client
```

### Restart Container

```bash
docker restart locker-client
```

## Troubleshooting

### Permission Denied on Serial Device

If you encounter permission errors accessing the serial device:

1. Add your user to the `dialout` group (on the host):
   ```bash
   sudo usermod -a -G dialout $USER
   ```

2. Alternatively, run the container with elevated privileges:
   ```bash
   docker run -d \
     --name locker-client \
     --privileged \
     --device=/dev/ttyACM0:/dev/ttyACM0 \
     --env-file .env \
     ghcr.io/open-locker/locker-client:latest
   ```

### Container Exits Immediately

Check the logs to identify the issue:
```bash
docker logs locker-client
```

Common issues:
- Missing or invalid `PROVISIONING_TOKEN`
- Serial device not accessible
- Incorrect MQTT broker configuration

### Finding Your Serial Device

List available serial devices:
```bash
ls -l /dev/ttyACM* /dev/ttyUSB*
```

## Development

For development setup and building from source, see the main project documentation.
