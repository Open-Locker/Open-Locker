---
title: Getting started
description: Set up a local development environment for the backend, mobile app, and locker client.
sidebar:
  order: 2
---

This guide sets up a local development environment. For a production
deployment, see [Operations](/dokumentation/operations/).

## Prerequisites

- **Docker** & Docker Compose
- **PHP 8.4+** and **Composer** (backend)
- **Node.js 22+** and **pnpm** (mobile app, locker client, website)
- **just** (task runner, optional but recommended)

## Clone the repository

```bash
git clone https://github.com/Open-Locker/Open-Locker.git
cd Open-Locker
```

## Backend

```bash
cp locker-backend/.env.example locker-backend/.env
```

Configure at least the following in `locker-backend/.env`:

- `APP_URL` — the backend's URL
- `DB_PASSWORD` — database password
- `MOSQ_HTTP_PASS` — shared secret for MQTT broker ↔ backend authentication

Generate the MQTT broker configuration and start the stack:

```bash
just setup-mqtt          # generates mosquitto.conf from the template

cd locker-backend
docker compose up -d     # Postgres, Mosquitto, Redis, app
docker compose exec app php artisan migrate --seed
```

Set `ADMIN_EMAIL` before the first start, or create the first administrator
explicitly:

```bash
docker compose exec app php artisan first-admin:create admin@example.com
```

The administrator sets their password through "forgot password", so mail
delivery must work. The admin panel is then available at `<APP_URL>/admin`.
The primary dev loop runs via Composer:

```bash
composer dev             # server + queue + logs + Vite concurrently
composer test            # tests
composer quality         # format check + static analysis + tests
```

## Mobile app

```bash
cd mobile-app
cp .env.example .env
pnpm install
```

Set `EXPO_PUBLIC_API_BASE_URL` in `.env` to the running backend, including
`/api`.

The app connects to the Reverb server the backend advertises. The local stack
advertises `localhost` on port `8080` (`FORWARD_REVERB_PORT`). How a device
reaches it depends on the device:

- **iOS Simulator:** works as is; it shares the computer's network.
- **Android emulator, or an Android phone over USB:** forward the port, or live
  updates show as paused:

  ```bash
  adb reverse tcp:8080 tcp:8080
  ```

- **iPhone (also over USB), or any phone on Wi-Fi:** set `VITE_REVERB_HOST` in
  `locker-backend/.env` to the computer's LAN address, then recreate the
  containers with `docker compose up -d` in `locker-backend/`.
  `docker compose restart` keeps the old value.

`pnpm start` targets an installed **Expo development client**; build one with
`pnpm android` or `pnpm ios`. Use `pnpm start:go` for the more limited
**Expo Go** workflow.

The API client is generated from the running backend's live OpenAPI
specification at `/docs/api.json`. After changing the API contract:

```bash
pnpm generate:api        # backend must be running
```

Before pushing: `pnpm check` (typecheck + lint + format + expo-doctor).

## Locker client

```bash
cd locker-client
pnpm install
pnpm dev                 # runs the client locally
```

The client needs a `config/locker-config.yml` and a `.env` with a
`PROVISIONING_TOKEN`. Hardware details are listed in the
[Bill of Materials](https://github.com/Open-Locker/Open-Locker/blob/main/docs/Bill-of-Materials.md).

## ESP32 firmware

See [Develop the ESP32 firmware](/dokumentation/esp32-development/).

## Website

```bash
cd website
pnpm install
pnpm dev                 # http://localhost:4321
```
