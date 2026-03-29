# Locker Client

Docker-based client for managing Open Locker hardware via Modbus and MQTT.

By default, deployments track `ghcr.io/open-locker/locker-client:latest`.
Tagged client releases can also be deployed explicitly without coupling them to
backend releases.

## Quick Start

### Prerequisites

1. Create a configuration directory with `locker-config.yml`
2. Copy `.env.example` to `.env`
3. Set `PROVISIONING_TOKEN` in `.env` for new lockers

See [docs/CONFIGURATION.md](docs/CONFIGURATION.md) for detailed setup instructions.

### Run with Docker Compose (Recommended)

```bash
# 1. Copy the example configuration files
cp locker-config.yml.example config/locker-config.yml
cp .env.example .env

# 2. Edit config/locker-config.yml with your settings

# 3. Edit .env as needed
# - set PROVISIONING_TOKEN for first-time provisioning
# - keep LOCKER_CLIENT_IMAGE_TAG=latest for the default channel
# - or pin a tagged client release

# 4. Start the containers
docker-compose up -d
```

The Compose setup starts two services:
- `locker-client` runs the actual client
- `watchtower` watches for new images and recreates the labeled client
  container automatically

### Run with Docker

```bash
docker run -d \
  --name locker-client \
  --device=/dev/ttyACM0:/dev/ttyACM0 \
  -e PROVISIONING_TOKEN="YOUR_TOKEN_HERE" \
  -v $(pwd)/config:/config:ro \
  -v locker-data:/data \
  --restart unless-stopped \
  ghcr.io/open-locker/locker-client:latest
```

## Environment File

The client ships with a `.env.example` file for deployment-related variables:

```bash
cp .env.example .env
```

Common variables:
- `LOCKER_CLIENT_IMAGE_TAG` chooses the image tag to deploy, defaulting to
  `latest`
- `PROVISIONING_TOKEN` is only needed for first-time provisioning
- `WATCHTOWER_POLL_INTERVAL` controls how often Watchtower checks for updates
- `TZ` sets the timezone used by Watchtower

## Release And Deployment Process

### Default Channel: `latest`

The default Compose setup uses:

```bash
ghcr.io/open-locker/locker-client:${LOCKER_CLIENT_IMAGE_TAG:-latest}
```

If you do nothing, deployments stay on `latest` and Watchtower will pull new
client images automatically.

### Deploy A Tagged Client Release

If you want a deterministic client deployment, pin the image tag in `.env`
before running Compose:

```bash
sed -i.bak 's/^LOCKER_CLIENT_IMAGE_TAG=.*/LOCKER_CLIENT_IMAGE_TAG=1.2.3/' .env
docker-compose pull
docker-compose up -d
```

This keeps backend and client releases independent. The client image can be
rolled forward or back by changing only `LOCKER_CLIENT_IMAGE_TAG`.

### Publish An Independent Client Release

The GitHub Actions workflow publishes the client image automatically:
- pushes to `main` update the `latest` image
- pushing a Git tag named `locker-client-v1.2.3` publishes the image tag
  `1.2.3`

Example:

```bash
git tag locker-client-v1.2.3
git push origin locker-client-v1.2.3
```

That release flow is intentionally client-specific, so backend releases can
happen independently.

## Automatic Updates With Watchtower

Watchtower is included in `docker-compose.yml` and is configured to update only
containers explicitly labeled for Watchtower.

Current defaults:
- checks every 300 seconds
- cleans up replaced images
- only updates labeled containers
- uses `TZ` from the environment, falling back to `UTC`

Optional environment variables:

```bash
export TZ="Europe/Berlin"
export WATCHTOWER_POLL_INTERVAL="300"
```

You can also set the same values in `.env`, which is the recommended approach
for persistent deployments.

### Watchtower Behavior With Tagged Releases

Watchtower follows the tag configured for the running container image:
- `latest` means the deployment automatically tracks the newest default image
- `1.2.3` means the deployment stays pinned to `1.2.3` until you change the tag

This keeps the default setup simple while still allowing controlled rollouts.

## Configuration

Configuration is now managed via YAML files and Docker volumes instead of environment variables.

### Volume Structure

- **`/config`** - Configuration files (mount read-only)
  - `locker-config.yml` - Main configuration (required)

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
  flashDurationMs: 200
  clients:
    - id: locker1
      slaveId: 1
    - id: locker2
      slaveId: 2
```

**Note:** Modbus clients no longer have individual `port` properties. All clients use the port defined in `modbus.port`.
`modbus.flashDurationMs` configures the hardware pulse duration for supported
Waveshare boards with native flash support.

### Device Access

The container requires access to the serial device for Modbus communication:
- **Device**: `/dev/ttyACM0` (or your specific serial device)
- **Mount**: Use `--device` flag to grant container access

⚠️ **Note**: Ensure your serial device path matches the one in your
`config/locker-config.yml` file and the mapped device in `docker-compose.yml`.

## Docker Commands

### View Logs

```bash
docker logs -f locker-client
```

### View Watchtower Logs

```bash
docker logs -f locker-client-watchtower
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

### Redeploy A Specific Client Version

```bash
sed -i.bak 's/^LOCKER_CLIENT_IMAGE_TAG=.*/LOCKER_CLIENT_IMAGE_TAG=1.2.3/' .env
docker-compose pull
docker-compose up -d
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
- Missing or invalid `PROVISIONING_TOKEN` environment variable
- Serial device not accessible
- Incorrect MQTT broker configuration
- Unexpected update behavior because `LOCKER_CLIENT_IMAGE_TAG` is pinned to an
  older release

### Finding Your Serial Device

List available serial devices:
```bash
ls -l /dev/ttyACM* /dev/ttyUSB*
```

## Development

For development setup and building from source, see the main project documentation.
