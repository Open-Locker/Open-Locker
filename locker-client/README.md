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
chmod 700 data
cp locker-config.yml.example config/locker-config.yml

# Issue a one-time token in the backend admin, immediately set
# PROVISIONING_TOKEN in .env, and adjust the serial port.
docker compose up -d
docker compose logs -f locker-client
```

The backend never shows the token again. If it is lost or consumed before the
client finishes provisioning, restart provisioning in the admin panel and use
the newly issued token.

Rotating the backend's Laravel `APP_KEY` invalidates outstanding, unconsumed
provisioning tokens. Issue replacement tokens for those clients; already
provisioned clients keep using their MQTT credentials and need no change.

The Compose stack runs the client from
`ghcr.io/open-locker/locker-client:${LOCKER_CLIENT_IMAGE_TAG:-latest}` and uses
Watchtower for labeled automatic updates.

Required mounts:

- `/config/locker-config.yml`: operator-managed Modbus/base configuration
- `/data`: client identity, MQTT credentials, runtime config, and dedup state

Keep the host `data` directory private (`0700`). The client creates persistent
files with mode `0600` and atomically replaces them. Never make the data directory
world-writable and do not use `chmod 777`.

The production image permits only one running client per `DATA_DIR`. Its
entrypoint takes a nonblocking Linux `flock` on
`/data/.locker-client.lock` (or `${DATA_DIR}/.locker-client.lock`) before starting
Node and exits with code `75` if another process holds the lock. The lock belongs
to the running client process and is released automatically when that process
exits or is killed. The lock file itself may remain and must not be treated as
stale state or deleted for recovery.

This guarantee assumes `/data` is a bind mount on a local Linux filesystem, as in
the provided Raspberry Pi deployment. Lock behavior on NFS and CIFS is not
guaranteed. Starting Node directly outside the production Docker entrypoint
bypasses this deployment lock.

The persisted files are:

- `.mqtt-client-id`: stable MQTT session identity
- `.mqtt-credentials.json`: provisioned MQTT credentials
- `.runtime-config-overlay.json`: backend-managed physical mapping
- `.mqtt-dedup-state.json`: message deduplication, command outcomes, and pending
  responses

If an existing credentials, runtime-overlay, or dedup file is corrupt, startup
fails without replacing it. Restore the file from backup or repair it while the
service is stopped. An invalid client-ID file is also retained; restore it, set a
valid `MQTT_CLIENT_ID`, or deliberately delete it to create a new MQTT session.
Deleting dedup state or an invalid client ID can discard safety/session history
and must not be used as an automatic recovery action.

The production image currently remains root-based because Raspberry Pi serial
device group IDs are host-specific. `/config` is mounted read-only and `/data` is
writable, but switching to a fixed non-root UID without also mapping the real
serial-device GID would break hardware access. Do not work around this with broad
serial-device permissions. A non-root rollout must first provide an explicit
serial GID and matching ownership for the host `data` directory.

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
Persistence and corruption behavior is defined in
[ADR-0033](../docs/adr/0033-locker-client-local-persistence-hardening.md).

## MQTT resilience

Per ADR-0014: persistent session (`clean: false`), unlimited automatic reconnect.

## Hardware and Modbus documentation

- [Modbus configuration](docs/modbus-configuration.md)
- [Waveshare integration](docs/WAVESHARE_INTEGRATION.md)

## Build the image locally

```bash
docker build -t ghcr.io/open-locker/locker-client:local .
```
