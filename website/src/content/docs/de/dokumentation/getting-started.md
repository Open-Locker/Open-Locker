---
title: Loslegen
description: Lokale Entwicklungsumgebung für Backend, Mobile App und Locker Client aufsetzen.
sidebar:
  order: 2
---

Diese Anleitung richtet eine lokale Entwicklungsumgebung ein. Für ein
Produktions-Deployment siehe [Betrieb](/dokumentation/operations/).

## Voraussetzungen

- **Docker** & Docker Compose
- **PHP 8.4+** und **Composer** (Backend)
- **Node.js 22+** und **pnpm** (Mobile App, Locker Client, Website)
- **just** (Task-Runner, optional aber empfohlen)

## Repository klonen

```bash
git clone https://github.com/Open-Locker/Open-Locker.git
cd Open-Locker
```

## Backend

```bash
cp locker-backend/.env.example locker-backend/.env
```

In `locker-backend/.env` mindestens konfigurieren:

- `APP_URL` — URL des Backends
- `DB_PASSWORD` — Datenbank-Passwort
- `MOSQ_HTTP_USER` / `MOSQ_HTTP_PASS` — Zugangsdaten für die Kommunikation zwischen MQTT-Broker und Backend

MQTT-Broker-Konfiguration erzeugen und Stack starten:

```bash
just setup-mqtt          # erzeugt mosquitto.conf aus dem Template

cd locker-backend
docker compose up -d     # Postgres, Mosquitto, Redis, App
php artisan migrate --seed
```

Ersten Admin-Benutzer anlegen:

```bash
docker compose exec app php artisan filament:user
```

Das Admin-Panel ist dann unter `<APP_URL>/admin` erreichbar. Der primäre
Dev-Loop läuft über Composer:

```bash
composer dev             # Server + Queue + Logs + Vite parallel
composer test            # Tests
composer quality         # Format-Check + statische Analyse + Tests
```

## Mobile App

```bash
cd mobile-app
pnpm install
pnpm start               # Expo Dev Client
```

Der API-Client wird aus der OpenAPI-Spezifikation des laufenden Backends
generiert. Nach Änderungen am API-Vertrag:

```bash
pnpm generate:api        # Backend muss laufen
```

Vor dem Pushen: `pnpm check` (Typecheck + Lint + Format + expo-doctor).

## Locker Client

```bash
cd locker-client
pnpm install
pnpm dev                 # startet den Client lokal
```

Der Client braucht eine `config/locker-config.yml` und eine `.env` mit
`PROVISIONING_TOKEN`. Details zur Hardware stehen in der
[Stückliste](https://github.com/Open-Locker/Open-Locker/blob/main/docs/Bill-of-Materials.de.md).

## Website

```bash
cd website
pnpm install
pnpm dev                 # http://localhost:4321
```
