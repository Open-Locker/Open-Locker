# Quick Reference

## Directory Structure
```
locker-client/
├── config/                          # Configuration volume
│   └── locker-config.yml           # Main config (required)
│
└── data/                            # Data volume (persistent)
    ├── .mqtt-client-id             # Generated
    ├── .mqtt-credentials.json      # Generated after provisioning
    └── .provisioning-state         # Generated after provisioning
```

## Quick Start

```bash
# 1. Download setup script
wget https://raw.githubusercontent.com/Open-Locker/Open-Locker/main/locker-client/setup.sh
chmod +x setup.sh

# 2. Run setup
./setup.sh

# 3. Review .env and config/locker-config.yml

# 4. Start
docker-compose up -d

# 5. Check logs
docker-compose logs -f
```

## Environment File

```bash
cp .env.example .env
```

Most important variables:
- `LOCKER_CLIENT_IMAGE_TAG=latest`
- `PROVISIONING_TOKEN=`
- `WATCHTOWER_POLL_INTERVAL=300`
- `TZ=UTC`

## Image Tags

Default behavior:

```bash
grep '^LOCKER_CLIENT_IMAGE_TAG=' .env
```

Deploy a tagged client release:

```bash
sed -i.bak 's/^LOCKER_CLIENT_IMAGE_TAG=.*/LOCKER_CLIENT_IMAGE_TAG=1.2.3/' .env
docker-compose pull
docker-compose up -d
```

Client release publishing:

```bash
git tag locker-client-v1.2.3
git push origin locker-client-v1.2.3
```

`locker-client-v1.2.3` publishes the container image tag `1.2.3`.

## Configuration Template

**config/locker-config.yml:**
```yaml
mqtt:
  brokerUrl: mqtt://your-broker.com
  defaultUsername: provisioning_client
  defaultPassword: your_password
  heartbeatInterval: 15

modbus:
  port: /dev/ttyACM0
  flashDurationMs: 200
  clients:
    - id: locker1
      slaveId: 1
```

## Docker Commands

```bash
# Start
docker-compose up -d

# Stop
docker-compose down

# Restart
docker-compose restart

# View logs
docker-compose logs -f

# View watchtower logs
docker-compose logs -f watchtower

# Inspect config
docker-compose exec locker-client ls -la /config

# Inspect data
docker-compose exec locker-client ls -la /data

# Shell access
docker-compose exec locker-client sh
```

## Provisioning

**First time (not provisioned):**
```bash
export PROVISIONING_TOKEN="YOUR_TOKEN_HERE"
docker-compose up -d
```

## Watchtower

Watchtower is included in the default Compose setup and updates only labeled
containers.

Optional environment variables:

```bash
sed -i.bak 's/^TZ=.*/TZ=Europe\\/Berlin/' .env
sed -i.bak 's/^WATCHTOWER_POLL_INTERVAL=.*/WATCHTOWER_POLL_INTERVAL=300/' .env
```

Behavior:
- `LOCKER_CLIENT_IMAGE_TAG=latest` tracks the latest published client image
- `LOCKER_CLIENT_IMAGE_TAG=1.2.3` stays pinned until you change the tag

**Re-provisioning:**
```bash
docker-compose down
rm data/.provisioning-state data/.mqtt-credentials.json
export PROVISIONING_TOKEN="NEW_TOKEN"
docker-compose up -d
```

## Volume Management

**Backup data:**
```bash
docker run --rm -v locker-data:/data -v $(pwd):/backup \
  alpine tar czf /backup/locker-data-backup.tar.gz -C /data .
```

**Restore data:**
```bash
docker run --rm -v locker-data:/data -v $(pwd):/backup \
  alpine tar xzf /backup/locker-data-backup.tar.gz -C /data
```

**Inspect volume:**
```bash
docker volume inspect locker-data
```

## Troubleshooting

**Config not found:**
```bash
# Check file exists
ls -la config/locker-config.yml

# Check mount
docker-compose exec locker-client cat /config/locker-config.yml
```

**Provisioning fails:**
```bash
# Check environment variable is set
echo $PROVISIONING_TOKEN

# View logs
docker-compose logs -f
```

**Reset everything:**
```bash
docker-compose down
rm -rf data/*
export PROVISIONING_TOKEN="NEW_TOKEN"
docker-compose up -d
```
