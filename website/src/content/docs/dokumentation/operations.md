---
title: Operations
description: Production deployment of the backend, the locker client on site, monitoring, and hosting options.
sidebar:
  order: 4
---

## Deploying the cloud backend

The backend runs as a Docker Compose stack on a central server (VPS or cloud
instance). A standalone deployment adds the maintained Traefik edge:

```bash
cd locker-backend
docker compose \
  -f docker-compose.prod.yml \
  -f docker-compose.prod.traefik.yml \
  up -d
```

Set `APP_DOMAIN`, `REVERB_DOMAIN`, `MQTT_DOMAIN`, and `ACME_EMAIL` first. The
public contract is HTTPS on 443 and MQTTS on 8883. Mosquitto port 1883 is
plaintext only inside the Docker network and is not published in production.

For Coolify v4, use the Git-based **Docker Compose** build pack and set
**Docker Compose Location** to
`/locker-backend/docker-compose.prod.coolify.yml`. That file inlines the
production stack because Coolify does not resolve Compose `extends` or
`include`. Coolify's similarly named custom Compose override configures
Coolify's own infrastructure and is not an application overlay. The
managed Traefik proxy must publish a TCP `mqtts` entrypoint on 8883; the adapter
routes `HostSNI(MQTT_DOMAIN)` through that entrypoint to Mosquitto port 1883. A
normal HTTPS domain route or direct port mapping does not secure MQTT. Follow
the installation guide for the exact procedure and required external
verification; live Coolify routing and certificate issuance are not proven by
repository validation.

The complete standalone and Coolify procedures, including firewall, DNS,
certificate, and smoke-test steps, are maintained in the repository
[installation guide](https://github.com/Open-Locker/Open-Locker/blob/main/docs/Installation.md).

### Pin the image version

For beta and production deployments, pin the image to an immutable release tag
in `locker-backend/.env`; do not deploy `latest`:

```bash
BACKEND_IMAGE_TAG=backend-v1.0.0-beta.1
```

```bash
docker compose \
  -f docker-compose.prod.yml \
  -f docker-compose.prod.traefik.yml \
  pull
docker compose \
  -f docker-compose.prod.yml \
  -f docker-compose.prod.traefik.yml \
  up -d --force-recreate
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

Locker clients use `mqtts://<mqtt-domain>:8883` and validate the public
certificate and hostname. Before accepting a deployment, test an authenticated
MQTT round trip through 8883 and confirm that port 1883 is unreachable from
outside the Docker host.

Set the same valid Laravel `APP_KEY` on every backend instance. The backend
derives a domain-separated provisioning-token HMAC subkey from it; no additional
provisioning HMAC secret is required. Rotating `APP_KEY` invalidates outstanding,
unconsumed provisioning tokens, so issue new tokens for those open
provisionings. Already provisioned devices continue to use their MQTT
credentials.

### Create an admin user

Set `ADMIN_EMAIL` before the first deployment, or run:

```bash
docker compose exec app php artisan first-admin:create admin@example.com
```

Mail delivery must work so the administrator can set a password. The admin
panel is available at `https://<your-domain>/admin`.

## Monitoring

- **Health endpoint**: `GET /up` (Laravel)
- **MQTT listener**: reports liveness via a heartbeat in the cache;
  `php artisan mqtt:health` is the Docker healthcheck of the `mqtt-listener`
  container. An `autoheal` sidecar automatically restarts unhealthy
  containers. Note: `autoheal` uses the Docker restart API — restarts show up
  in the `autoheal` logs, not in `RestartCount`.
- **Offline detection**: the scheduler runs
  `php artisan locker:detect-offline` every minute

## Locker client on site

The locker client runs as a Docker container on a Raspberry Pi (3/4/5 or
Zero 2 W, Raspberry Pi OS Lite 64-bit):

- Image: pin an immutable release such as
  `ghcr.io/open-locker/locker-client:client-v1.0.0-beta.1`
- Requires `config/locker-config.yml` and a `.env` with a
  `PROVISIONING_TOKEN`
- Connects to the backend via MQTT and drives Waveshare relay boards via
  serialized Modbus RTU

Issue or restart provisioning in the admin panel, copy the token from the
one-time dialog directly into the client's `.env`, and restart it. The token
cannot be viewed again; issue a new one if it is lost. Do not delete persisted
identity, credentials, runtime configuration, or deduplication state as a
routine recovery step.

Recommended hardware: see the
[Bill of Materials](https://github.com/Open-Locker/Open-Locker/blob/main/docs/Bill-of-Materials.md).

## Hosting options

- **Self-hosting**: run everything yourself — full control, no software costs
- **Hosted backend**: if you don't want to host it yourself, the central
  backend can be hosted for you — see the [offer on the website](/#hosting)
