# Locker Client

Docker-based client for managing Open Locker hardware via Modbus and MQTT.

## Quick Start

### Prerequisites

1. Create a configuration directory with `locker-config.yml`
2. Optionally add a `provisioning-token` file for new lockers

See [docs/CONFIGURATION.md](docs/CONFIGURATION.md) for detailed setup instructions.

### Run with Docker Compose (Recommended)

```bash
# 1. Copy the example configuration
cp locker-config.yml.example config/locker-config.yml

# 2. Edit config/locker-config.yml with your settings

# 3. (Optional) Add provisioning token for new lockers
echo "YOUR_TOKEN_HERE" > config/provisioning-token

# 4. Start the container
docker-compose up -d
```

### Run with Docker

```bash
docker run -d \
  --name locker-client \
  --device=/dev/ttyACM0:/dev/ttyACM0 \
  -v $(pwd)/config:/config:ro \
  -v locker-data:/data \
  --restart unless-stopped \
  ghcr.io/open-locker/locker-client:latest
```

## Configuration

Configuration is now managed via YAML files and Docker volumes instead of environment variables.

### Volume Structure

- **`/config`** - Configuration files (mount read-only)
  - `locker-config.yml` - Main configuration (required)
  - `provisioning-token` - One-time provisioning token (optional, auto-deleted)

- **`/data`** - Persistent runtime data (mount read-write)
  - `.mqtt-client-id` - Generated client identifier
  - `.mqtt-credentials.json` - Provisioned credentials
  - `.provisioning-state` - Provisioning status

### Configuration File

Create a `locker-config.yml` file (see [locker-config.yml.example](locker-config.yml.example)):

```yaml
mqtt:
  brokerUrl: mqtt://open-locker.cloud
  defaultUsername: provisioning_client
  defaultPassword: a_public_password
  heartbeatInterval: 15

modbus:
  port: /dev/ttyACM0
  clients:
    - id: locker1
      slaveId: 1
    - id: locker2
      slaveId: 2
```

**Note:** Modbus clients no longer have individual `port` properties. All clients use the port defined in `modbus.port`.

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
