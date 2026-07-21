---
title: Getting started
description: Set up a local development environment for the backend, mobile app, and locker client.
sidebar:
  order: 2
---

This guide sets up a local development environment. For a production
deployment, see [Operations](/en/dokumentation/operations/).

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
- `MOSQ_HTTP_USER` / `MOSQ_HTTP_PASS` — credentials for MQTT broker ↔ backend communication

Generate the MQTT broker configuration and start the stack:

```bash
just setup-mqtt          # generates mosquitto.conf from the template

cd locker-backend
docker compose up -d     # Postgres, Mosquitto, Redis, app
php artisan migrate --seed
```

Create the first admin user:

```bash
docker compose exec app php artisan filament:user
```

The admin panel is then available at `<APP_URL>/admin`. The primary dev loop
runs via Composer:

```bash
composer dev             # server + queue + logs + Vite concurrently
composer test            # tests
composer quality         # format check + static analysis + tests
```

## Mobile app

```bash
cd mobile-app
pnpm install
pnpm start               # Expo dev client
```

The API client is generated from the running backend's OpenAPI specification.
After changing the API contract:

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

## Website

```bash
cd website
pnpm install
pnpm dev                 # http://localhost:4321
```
