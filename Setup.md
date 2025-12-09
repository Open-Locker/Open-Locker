# Project Setup Guide (Dev)

This guide describes the steps to set up and run the project for **local development**.
For production deployment, see [`docs/Installation.md`](docs/Installation.md).

## 1. Prerequisites

Make sure the following tools are installed on your system:

- **Docker & Docker Compose**
- **Git**
- **Just** (Task runner, optional aber empfohlen):
  - macOS (Homebrew): `brew install just`
  - Linux: siehe [Just-Repository](https://github.com/casey/just)

## 2. Repository klonen

```bash
git clone https://github.com/Open-Locker/Open-Locker.git
cd Open-Locker
```

Die Struktur:

- `locker-backend/` – Laravel API + Admin Panel
- `locker_app/` – Flutter App
- `packages/locker_api/` – generierter Dart API-Client

## 3. Backend Setup (Laravel, Dev mit Sail)

### 3.1 .env anlegen

```bash
cd locker-backend
cp .env.example .env
```

Passe in `.env` mindestens an:

- `APP_URL=http://localhost`
- DB-Einstellungen (Standard ist PostgreSQL aus `docker-compose.yml`)
- MQTT/HTTP-Auth:
  - `MOSQ_HTTP_USER`
  - `MOSQ_HTTP_PASS`

### 3.2 Abhängigkeiten installieren

**Variante A: Lokaler Composer (empfohlen, wenn vorhanden)**

```bash
composer install
```

**Variante B: Composer im Container ohne Sail (kein lokaler PHP/Composer nötig)**

1. Stelle sicher, dass Docker läuft.
2. Starte die Container einmalig:

   ```bash
   docker compose up -d
   ```

3. Führe Composer im `app`-Container aus:

   ```bash
   docker compose exec app composer install
   ```

   Damit werden alle PHP-Abhängigkeiten im Container installiert.

**Variante C: Composer über Docker/Sail (nachdem Composer einmal gelaufen ist)**

Falls du keinen lokalen PHP 8.4 / Composer installieren möchtest:

1. Stelle sicher, dass Docker läuft.
2. Starte die Container (mindestens einmal), damit Sail verfügbar ist:

   ```bash
   ./vendor/bin/sail up -d
   ```

3. Führe Composer im Container über Sail aus:

   ```bash
   ./vendor/bin/sail composer install
   ```

   Das installiert alle PHP-Abhängigkeiten innerhalb des Docker-Containers.

### 3.3 Docker/Sail starten

```bash
./vendor/bin/sail up -d
```

Dies startet:

- `app` (Laravel)
- `pgsql` (PostgreSQL)
- `redis`
- `mqtt` (Mosquitto mit HTTP-Auth)
- Worker-Container

### 3.4 Datenbank migrieren & seeden

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

### 3.5 Storage-Link erstellen

```bash
./vendor/bin/sail artisan storage:link
```

## 4. MQTT / Mosquitto im Dev-Setup

Für die HTTP-Authentifizierung von Mosquitto gegen das Laravel-Backend nutzen wir
`mosquitto-go-auth` und eine generierte Config.

### 4.1 Auth-Config mit Just erzeugen

Aus dem Projekt-Root:

```bash
just setup-mqtt
```

Das Skript:

- liest `MOSQ_HTTP_USER`/`MOSQ_HTTP_PASS` aus `locker-backend/.env`,
- erstellt aus dem Template `locker-backend/mosquitto/mosquitto.conf.template`
  die Datei `locker-backend/mosquitto/mosquitto.conf`,
- setzt dort den `auth_opt_http_extra_headers`-Eintrag,
- startet den `mqtt`-Container neu.

## 5. Laufende Services aufrufen

- **Backend (API/Admin)**: `http://localhost`
- **Admin Panel**: `http://localhost/admin`
  - Zugangsdaten aus Seeder (`database/seeders/DatabaseSeeder.php`).
- **API-Doku**: `http://localhost/docs/api`
- **Mailpit**: `http://localhost:8025`

## 6. Notes und Troubleshooting

- 500-Fehler → `.env` prüfen (APP_KEY, DB-Config, MQTT-Config).
- Container starten nicht → `git pull` ausführen, dann erneut `./vendor/bin/sail up -d`.
- MQTT/Auth-Probleme → `just setup-mqtt` erneut ausführen und Logs prüfen:
  - `./vendor/bin/sail logs mqtt` (bzw. `docker compose logs mqtt` im Backend-Verzeichnis).

## 7. Optionale Konfiguration

- **Git Hooks**: Git Hooks im Projekt installieren:

```bash
just install-hooks
```

> TODO: Das komplette Dev-Setup (Sail-Start, Migrationen, Storage-Link, Tests)
> perspektivisch ebenfalls über `just`-Targets kapseln (z. B. `just dev-up`,
> `just dev-reset-db`), damit neue Entwickler noch weniger manuelle Schritte
> benötigen.
