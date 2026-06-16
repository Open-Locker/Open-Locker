# locker-client v2

Hexagonal rewrite of the Open-Locker edge client. See:

- [Implementation plan](../docs/plans/locker-client-v2-rewrite.md)
- [ADR-0021](../docs/adr/0021-locker-client-v2-hexagonal-rewrite.md)

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

## MQTT resilience

Per ADR-0014: persistent session (`clean: false`), unlimited automatic reconnect.

## Docker

```bash
docker build -t ghcr.io/open-locker/locker-client:v2 .
```
