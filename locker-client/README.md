# Locker Client

Docker-based Raspberry Pi service that bridges the Open-Locker backend and
physical locker hardware:

`MQTT ↔ application use cases ↔ serialized Modbus RTU ↔ Waveshare relay boards`

The current implementation is the hexagonal TypeScript rewrite accepted in
[ADR-0024](../docs/adr/0024-locker-client-v2-hexagonal-rewrite.md).

## Hardware warning

Compartment open uses **Waveshare hardware flash** only. Locks must receive brief
pulses (100–500ms). Never energize relays via software ON/OFF timers.

## Raspberry Pi deployment

```bash
cp .env.example .env
mkdir -p config data
cp locker-config.yml.example config/locker-config.yml

# Set PROVISIONING_TOKEN in .env for the first startup and adjust the serial port.
docker compose up -d
docker compose logs -f locker-client
```

The Compose stack runs the client from
`ghcr.io/open-locker/locker-client:${LOCKER_CLIENT_IMAGE_TAG:-latest}` and uses
Watchtower for labeled automatic updates.

Required mounts:

- `/config/locker-config.yml`: operator-managed Modbus/base configuration
- `/data`: client identity, MQTT credentials, runtime config, and dedup state

## Development

```bash
pnpm install
pnpm test
pnpm check
pnpm dev
```

Requires `/config/locker-config.yml` and `/data` volumes (or env `CONFIG_DIR` /
`DATA_DIR`).

Compartment mapping and heartbeat interval are **not** part of the base YAML.
The backend pushes them via MQTT `apply_config`; the client persists the result
in `/data/.runtime-config-overlay.json`. Until that first apply completes,
`open_compartment` commands fail and compartment snapshots stay empty.

See [ADR-0025](../docs/adr/0025-locker-client-v2-runtime-only-compartment-mapping.md).

## MQTT resilience

Per ADR-0014: persistent session (`clean: false`), unlimited automatic reconnect.

## Hardware and Modbus documentation

- [Modbus configuration](docs/modbus-configuration.md)
- [Waveshare integration](docs/WAVESHARE_INTEGRATION.md)

## Build the image locally

```bash
docker build -t ghcr.io/open-locker/locker-client:local .
```
