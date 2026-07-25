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

## Fleet simulator

Emulates one or many locker banks with no hardware, for developing the backend,
the API/mobile visibility path, and MQTT workflows.

**→ Step-by-step guide: [docs/simulator.md](../docs/simulator.md)**

```bash
cp simulator-scenario.yml.example config/simulator-scenario.yml
# put a real provisioning token from the admin panel in each bank
pnpm sim
```

```
list                          show every bank and its door states
open|close|unknown <bank> <n> change a door; publishes a fresh retained snapshot
quit                          shut down
```

Flags: `--scenario <path>`, `--broker <url>`, `--no-interactive`, `--quiet`,
`--allow-production`.

Every MQTT message is echoed to the console, so you can watch a workflow without
attaching an MQTT client (`--quiet` turns it off):

```
[bank-a] → state/compartments (retained)  #1:closed #2:closed
[bank-a] ← command  open_compartment #1 tx=13a275c2
[bank-a] → state/compartments (retained)  #1:open #2:closed
[bank-a] → response  open_compartment success tx=13a275c2
```

The simulator is a **second adapter behind `LockerBusPort`**, not a mode of the
real client: `InMemoryLockerBus` replaces Modbus, and everything above the port —
use cases, dispatcher, envelope builder, dedup, schemas — is the production code
path. That is what keeps its payloads contract-valid by construction.
`src/main.ts` and `src/bootstrap/createApp.ts` are untouched by it.

Two things worth knowing before you run it:

- **A bank provisions exactly once.** The backend sets `provisioned_at` and
  refuses every later attempt, so the credentials issued on the first run are
  cached in `.simulator-credentials.json` beside the scenario file (git-ignored,
  `0600`, override with `SIMULATOR_CREDENTIALS_FILE`). Delete that file and the
  bank becomes unusable until an admin resets its provisioning.
- **Never point it at production.** It publishes fake state under a real locker
  UUID. It refuses to start when `APP_ENV`/`NODE_ENV` is `production` unless you
  pass `--allow-production`, and a bank must never be simulated while its real
  device is online — the broker drops one of them on session takeover.

See [ADR-0027](../docs/adr/0027-contract-aligned-locker-fleet-simulator.md).

## MQTT resilience

Per ADR-0014: persistent session (`clean: false`), unlimited automatic reconnect.

## Hardware and Modbus documentation

- [Modbus configuration](docs/modbus-configuration.md)
- [Waveshare integration](docs/WAVESHARE_INTEGRATION.md)

## Build the image locally

```bash
docker build -t ghcr.io/open-locker/locker-client:local .
```
