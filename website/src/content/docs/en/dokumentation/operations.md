---
title: Operations
description: Production deployment of the backend, the locker client on site, monitoring, and hosting options.
sidebar:
  order: 4
---

## Deploying the cloud backend

The backend runs as a Docker Compose stack on a central server (VPS or cloud
instance):

```bash
cd locker-backend
docker compose -f docker-compose.prod.yml up -d
```

### Pin the image version (recommended)

By default the `latest` tag is used. For production, pin the image to an
immutable tag — a commit SHA or release tag in `locker-backend/.env`:

```bash
BACKEND_IMAGE_TAG=<github_sha>
```

```bash
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d --force-recreate
```

The running version is exposed via `GET /api/identify` as `version`.

### MQTT authentication

The Mosquitto broker authenticates clients against the Laravel API
(`mosquitto-go-auth`). The configuration is generated from the template:

```bash
just setup-mqtt
```

Without `just`: copy `mosquitto.conf` from the example and add
`mosq_secret=<MOSQ_HTTP_PASS>` to the webhook URIs, then restart the
Mosquitto container.

### Create an admin user

```bash
docker compose exec app php artisan filament:user
```

The admin panel is available at `https://<your-domain>/admin`.

## Monitoring

- **Health endpoint**: `GET /up` (Laravel)
- **MQTT listener**: reports liveness via a heartbeat in the cache;
  `php artisan mqtt:health` is the Docker healthcheck of the `mqtt-listener`
  container. An `autoheal` sidecar automatically restarts unhealthy
  containers. Note: `autoheal` uses the Docker restart API — restarts show up
  in the `autoheal` logs, not in `RestartCount`.
- **Status polling**: `php artisan locker:poll-status` continuously monitors
  locker states (separate container)

## Locker client on site

The locker client runs as a Docker container on a Raspberry Pi (3/4/5 or
Zero 2 W, Raspberry Pi OS Lite 64-bit):

- Image: `ghcr.io/open-locker/locker-client:latest`
- Requires `config/locker-config.yml` and a `.env` with a
  `PROVISIONING_TOKEN`
- Connects to the backend via MQTT and drives the locks via Modbus
  (TCP or RTU)

Recommended hardware: see the
[Bill of Materials](https://github.com/Open-Locker/Open-Locker/blob/main/docs/Bill-of-Materials.md).

## Hosting options

- **Self-hosting**: run everything yourself — full control, no software costs
- **Hosted backend**: if you don't want to host it yourself, the central
  backend can be hosted for you — see the [offer on the website](/en/#hosting)
