# Installation

- [Architecture overview](#architecture-overview)
- [Release and security status](#release-and-security-status)
- [Cloud backend setup](#cloud-backend-setup)
  - [Standalone Docker deployment](#standalone-docker-deployment)
  - [Coolify deployment](#coolify-deployment)
  - [Verify MQTT TLS](#verify-mqtt-tls)
- [Locker client setup](#locker-client-setup-raspberry-pi)
- [Mobile app setup](#mobile-app-setup)

Open Locker is a monorepo with independently deployable backend, locker-client,
and mobile components. Docker provides the backend and Raspberry Pi runtime;
the mobile application uses React Native with Expo.

## Architecture overview

1. **Cloud backend (`locker-backend/`)**
   - Laravel 12 REST API and Filament 5 admin panel
   - PostgreSQL, Redis, queue/event workers, scheduler, and Laravel Reverb
   - Mosquitto with `mosquitto-go-auth`, authenticating through Laravel
   - publishes locker commands over MQTT and consumes client state/responses
   - never communicates with Modbus hardware directly
2. **Locker client (`locker-client/`)**
   - TypeScript/Node service in Docker on a Raspberry Pi
   - provisions and communicates with the backend through MQTT
   - owns serialized Modbus RTU communication with Waveshare hardware
   - persists MQTT identity, credentials, runtime configuration, and command
     deduplication state under `/data`
3. **Mobile app (`mobile-app/`)**
   - React Native application built with Expo
   - consumes the backend REST API through a generated RTK Query client
   - receives live updates through Reverb

The runtime flow is:

`Mobile app → Laravel API → MQTT → locker-client → Modbus RTU → locker hardware`

## Release and security status

There is currently **no production deployment**. The first beta source tags
have been cut, but artifact publication and field acceptance are not complete.
The controlled pre-production pilot is governed by the
[beta release checklist](release-checklist.md); the verified cut outcome is
recorded in the [Beta release notes](releases/beta.md).

Component source tags are independent:

- `backend-vX.Y.Z`
- `client-vX.Y.Z`
- `mobile-vX.Y.Z`

For beta deployments, set `BACKEND_IMAGE_TAG` and `LOCKER_CLIENT_IMAGE_TAG` to
the exact immutable GHCR tags produced and verified for the corresponding source
tags. Do not deploy a pilot with `latest`. Verify the image digest because
the release workflows also publish a commit-specific `sha-<commit>` tag.

Production MQTT is exposed only as MQTTS on port 8883 through Traefik. Plaintext
port 1883 remains available inside the production Docker network and in the
explicit development stack, but is not published by production Compose. Passing
the end-to-end MQTTS smoke test below is tracked as Beta 2 acceptance in #233,
after the first versioned backend and client images exist.

## Cloud backend setup

This setup is intended for development or a controlled server environment. A
public deployment additionally requires the security and operational gates in
the release checklist.

### 1. Initial setup

Clone the repository and prepare the environment:

```bash
git clone https://github.com/Open-Locker/Open-Locker.git
cd Open-Locker
cp locker-backend/.env.example locker-backend/.env
```

Edit `locker-backend/.env` and configure at least:

- `APP_URL` and a persistent `APP_KEY`
- PostgreSQL database name, user, and a strong `DB_PASSWORD`
- mail delivery, which is required for administrator password setup
- Reverb application credentials and public endpoint
- public application, Reverb, and MQTT DNS names
- backend MQTT credentials (`MQTT_USERNAME` and `MQTT_PASSWORD`)
- provisioning credentials (`MQTT_PROVISIONING_USERNAME` and
  `MQTT_PROVISIONING_PASSWORD`)
- `MOSQ_HTTP_PASS`, shared only between `mosquitto-go-auth` and Laravel

### 2. Configure MQTT authentication

Mosquitto authenticates clients against the Laravel `/api/mosq/*` endpoints.
The recommended repository task generates the local configuration:

```bash
just setup-mqtt
```

For manual setup:

1. Copy the Mosquitto configuration template to
   `locker-backend/mosquitto/mosquitto.conf`.
2. Set the auth and ACL webhook query secret to the same `MOSQ_HTTP_PASS` used
   by Laravel.
3. Restart Mosquitto.

This configures HTTP-backed authentication. Public transport encryption is
provided by Traefik according to
[ADR-0053](adr/0053-terminate-public-mqtt-tls-at-traefik.md).

### 3. Start services

Run the development stack from the backend directory:

```bash
cd locker-backend
docker compose up -d
```

Production supports two edge adapters over the same base stack:

- standalone Docker Compose starts the repository-maintained Traefik service;
- Coolify uses Coolify's managed Traefik service.

Both expose HTTPS on 443 and MQTTS on 8883. Normal production commands do not
publish Mosquitto's plaintext port 1883. The rollout procedure below defines the
only temporary exception for clients that have not migrated yet.

#### Standalone Docker deployment

Create DNS A/AAAA records for `APP_DOMAIN`, `REVERB_DOMAIN`, and `MQTT_DOMAIN`
that point to the server. Allow inbound TCP 80, 443, and 8883 in the provider
firewall. Set those domains and `ACME_EMAIL` in `locker-backend/.env`, then run:

```bash
cd locker-backend
export BACKEND_IMAGE_TAG="<immutable-release-image-tag>"
docker compose \
  -f docker-compose.prod.yml \
  -f docker-compose.prod.traefik.yml \
  pull
docker compose \
  -f docker-compose.prod.yml \
  -f docker-compose.prod.traefik.yml \
  up -d --force-recreate
```

`docker-compose.prod.yml` runs the app, PostgreSQL, Redis, queue and event
workers, scheduler, Reverb, MQTT listener, and Mosquitto. It maps
`BACKEND_IMAGE_TAG` to `APP_VERSION`; confirm it through `GET /api/identify`.
The Traefik overlay publishes 80, 443, and 8883, stores ACME state in the
`traefik-certificates` volume, and forwards decrypted MQTT only over the private
Compose network to `mqtt:1883`.

Do not copy the development `1883:1883` mapping into the base or edge adapter.
The time-limited migration overlay below is the only exception.
Docker-published ports can bypass host-only UFW rules; verify the rendered
Compose configuration as well as the upstream firewall.

#### Coolify deployment

Coolify's current documentation does not describe a per-resource **Custom
Compose Override**. Its similarly named custom override file configures
Coolify's own infrastructure under `/data/coolify/source`, not an application
resource, and must not be used for this stack.

Use Coolify v4's documented Git-based Docker Compose application instead:

1. Create DNS records for the application, Reverb, and MQTT hosts.
2. In **Project → Environment → Add Resource**, select the repository and choose
   the **Docker Compose** build pack. In the resource's **General** settings set
   **Docker Compose Location** to
   `/locker-backend/docker-compose.prod.coolify.yml`. This file is a
   self-contained copy of the production stack plus Coolify MQTT labels;
   Coolify does not resolve Compose `extends` or `include`. No UI override
   is required.
3. Add the values from `.env.prod.example` under the resource's
   **Environment Variables**. Use real secrets and domains, pin
   `BACKEND_IMAGE_TAG`, and set a deployment-unique
   `COOLIFY_MQTT_ROUTER_NAME` when multiple Open Locker stacks share a proxy.
   The adapter attaches Mosquitto to Coolify's external `coolify` network and
   defines the TCP router; it does not publish a broker port itself.
4. Open **Servers → your server → Proxy → Configuration**. Preserve the existing
   proxy configuration and add host port 8883 plus the static MQTT entrypoint:

   ```yaml
   ports:
     - "8883:8883"

   command:
     - "--entrypoints.mqtts.address=:8883"
   ```

   Merge these entries into the existing `ports` and `command` lists rather
   than replacing Coolify's HTTP/HTTPS settings.
5. Confirm that the proxy's ACME resolver is named `letsencrypt`, as referenced
   by the override. If the server uses a differently named resolver, update the
   `tls.certresolver` label to that existing name.
6. Restart the Coolify proxy, deploy the Open Locker resource, and allow
   inbound TCP 8883 in the provider firewall/security group. Do not add a
   `1883:1883` mapping in Coolify's port UI.

The MQTT hostname must match the `HostSNI` rule and certificate SAN exactly.
Coolify's normal HTTP domain route alone does not create a raw TCP MQTT route.
Traefik is configured to obtain and renew the certificate; Mosquitto does not
receive or mount the private key. The repository can validate the rendered
Compose model, but successful routing, ACME issuance, and renewal must still be
verified on the target Coolify v4 server.

Official references:

- [Coolify Docker Compose applications](https://coolify.io/docs/applications/build-packs/docker-compose)
- [Coolify custom infrastructure overrides](https://coolify.io/docs/knowledge-base/custom-compose-overrides)

After backend updates, restart long-running queue workers. This is mandatory for
the credential issuance migration described in PR #227.

### 4. Verify MQTT TLS

From a machine outside the Docker host, verify the certificate chain and
hostname:

```bash
export MQTT_DOMAIN=mqtt.example.com
openssl s_client \
  -connect "${MQTT_DOMAIN}:8883" \
  -servername "${MQTT_DOMAIN}" \
  -verify_hostname "${MQTT_DOMAIN}" \
  -verify_return_error </dev/null
```

Then use valid backend MQTT credentials to verify an authenticated round trip:

```bash
mosquitto_sub \
  -h "${MQTT_DOMAIN}" -p 8883 --capath /etc/ssl/certs \
  -u "<backend-mqtt-user>" -P "<backend-mqtt-password>" \
  -t "smoke/mqtt-tls" -C 1 &
mosquitto_pub \
  -h "${MQTT_DOMAIN}" -p 8883 --capath /etc/ssl/certs \
  -u "<backend-mqtt-user>" -P "<backend-mqtt-password>" \
  -t "smoke/mqtt-tls" -m "ok"
```

Repeat with a restricted device or provisioning identity and a topic outside
its documented ACL; the broker must reject it. Finally, confirm that plaintext
MQTT is not externally reachable:

```bash
nc -vz "${MQTT_DOMAIN}" 1883
```

For final acceptance, that command must fail. During the staged migration it
will still succeed by design, so record the MQTTS checks but do not close the
#233 acceptance until the migration overlay or legacy Coolify mapping is removed.
Also verify provisioning, heartbeat, a supervised open command, response, and
state update from a real client using `mqtts://${MQTT_DOMAIN}:8883`. HTTPS health
does not substitute for this TCP/TLS test.

### 5. Migrate existing plaintext clients

For a new deployment, skip this section and never expose port 1883. For an
existing deployment whose clients still use remote plaintext MQTT, use this
ordered, time-limited migration:

1. For standalone Compose, set `MQTT_MIGRATION_BIND_ADDRESS` to the server
   address currently used by old clients and add
   `docker-compose.prod.mqtt-migration.yml` to the command. For an existing
   Coolify resource that already publishes 1883, leave that legacy mapping
   unchanged during steps 2 and 3; do not add it to a resource where it is
   already absent.
2. Deploy and verify MQTTS on 8883 while the old clients remain connected.
3. Upgrade every production locker client to an image that requires `mqtts://`
   and configure `MQTT_BROKER_URL=mqtts://mqtt.example.com:8883`.
4. Redeploy without the migration overlay, unset
   `MQTT_MIGRATION_BIND_ADDRESS`, and verify externally that port 1883 fails.

Do not use this overlay for a new deployment or leave it enabled as a fallback.
After migration, deploy the repository-owned Coolify Compose definition; that
coordinated cutover removes the legacy mapping. Repository validation does not
prove that the target host firewall or Coolify proxy behaves correctly; verify
both ports externally.

### 6. Create the first administrator

Configure `ADMIN_EMAIL` before the first deployment, or run:

```bash
docker compose exec app php artisan first-admin:create admin@example.com
```

Mail delivery must work so the administrator can set a password. The Filament 5
admin panel is available under `/admin` on the configured backend URL.

## Locker client setup (Raspberry Pi)

### Prerequisites

- Raspberry Pi 3/4/5 or Zero 2 W with 64-bit Raspberry Pi OS
- Docker with the Compose plugin
- supported USB/RS485 connection and Waveshare relay hardware
- recommended hardware from the [Bill of Materials](Bill-of-Materials.md)
- a one-time provisioning token issued from the backend admin panel

### 1. Prepare files

```bash
cd locker-client
cp .env.example .env
mkdir -p config data
chmod 700 data
cp locker-config.yml.example config/locker-config.yml
```

Set `LOCKER_CLIENT_IMAGE_TAG` to the verified immutable beta image tag. Set the
one-time `PROVISIONING_TOKEN`, bootstrap MQTT credentials, and
`MQTT_BROKER_URL=mqtts://<mqtt-domain>:8883` in `.env`. The client uses the
operating-system CA store and always validates the certificate chain and
hostname for `mqtts://` connections. A private or self-signed CA is not supported
by the production reference deployment.

The token is shown only once. If it is lost or consumed before provisioning
finishes, restart provisioning in the admin panel and use the new token.

### 2. Configure Modbus RTU

Edit `config/locker-config.yml`:

```yaml
modbus:
  port: /dev/ttyACM0
  baudRate: 9600
  dataBits: 8
  stopBits: 1
  parity: none
  timeout: 1000
  flashDurationMs: 200
```

Map the same serial device in `locker-client/docker-compose.yml`. The client
supports the Modbus RTU/Waveshare path; the old backend Modbus integration and
the previous environment-based TCP/RTU examples do not describe the current
architecture. Compartment mapping is delivered later by the backend through
MQTT `apply_config` and persisted in `/data`.

### 3. Start and verify

```bash
docker compose up -d
docker compose logs -f locker-client
```

Verify the running image digest, successful provisioning/MQTT connection,
heartbeat, runtime configuration, Modbus reachability, and a supervised
compartment snapshot/open cycle. Keep `config/` and `/data` backed up and
private. Do not delete identity, credential, runtime-overlay, or dedup files as
a routine recovery step.

During PR #227's credential rollout, update the client before re-provisioning
any bank. Existing legacy credentials continue to work until deliberate
re-provisioning.

## Mobile app setup

Install and check the Expo application with pnpm:

```bash
cd mobile-app
cp .env.example .env
pnpm install
pnpm check
pnpm test:ci
```

Set `EXPO_PUBLIC_API_BASE_URL` in `.env` to the running backend, including the
`/api` path. Realtime uses `EXPO_PUBLIC_REVERB_KEY`,
`EXPO_PUBLIC_REVERB_PORT`, and `EXPO_PUBLIC_REVERB_SCHEME`; set
`EXPO_PUBLIC_REVERB_HOST` only when it differs from the API host. Keep those
values aligned with the backend's public Reverb configuration.

`pnpm start` targets an installed Expo development client. Build and launch one
with `pnpm android` or `pnpm ios`. Use `pnpm start:go` only for the more limited
Expo Go workflow.

The generated RTK Query client comes from the running backend's live Scramble
OpenAPI document at `/docs/api.json`:

```bash
pnpm generate:api
```

Run that command with the backend available at `EXPO_PUBLIC_API_BASE_URL` after
an API contract change. Beta distribution uses TestFlight for iOS and the
configured Android beta/internal track; versioned mobile releases use
`mobile-v*` tags. Store distribution and pilot recovery follow the release
checklist rather than the Docker deployment process.
