# locker-client v2

Hexagonal rewrite of the Open-Locker edge client. See:

- [Implementation plan](../docs/plans/locker-client-v2-rewrite.md)
- [ADR-0024](../docs/adr/0024-locker-client-v2-hexagonal-rewrite.md)

## Hardware warning

Compartment open uses **Waveshare hardware flash** only. Locks must receive brief
pulses (100–500ms). Never energize relays via software ON/OFF timers.

## Development

```bash
pnpm install
pnpm test
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

## Docker

```bash
docker build -t ghcr.io/open-locker/locker-client:v2 .
```
