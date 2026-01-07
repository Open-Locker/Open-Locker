# Quick Reference

## Directory Structure
```
locker-client/
├── config/                          # Configuration volume
│   ├── locker-config.yml           # Main config (required)
│   └── provisioning-token          # One-time token (optional, auto-deleted)
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

# 3. Start
docker-compose up -d

# 4. Check logs
docker-compose logs -f
```

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
echo "YOUR_TOKEN_HERE" > config/provisioning-token
docker-compose up -d
```

**Re-provisioning:**
```bash
docker-compose down
rm data/.provisioning-state data/.mqtt-credentials.json
echo "NEW_TOKEN" > config/provisioning-token
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
# Check token file exists
ls -la config/provisioning-token

# View logs
docker-compose logs -f
```

**Reset everything:**
```bash
docker-compose down
rm -rf data/*
echo "NEW_TOKEN" > config/provisioning-token
docker-compose up -d
```
