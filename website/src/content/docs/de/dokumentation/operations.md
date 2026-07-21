---
title: Betrieb
description: Produktions-Deployment des Backends, Locker Client am Standort, Monitoring und Hosting-Optionen.
sidebar:
  order: 4
---

## Cloud-Backend deployen

Das Backend läuft als Docker-Compose-Stack auf einem zentralen Server (VPS
oder Cloud-Instanz):

```bash
cd locker-backend
docker compose -f docker-compose.prod.yml up -d
```

### Image-Version pinnen (empfohlen)

Standardmäßig wird der `latest`-Tag verwendet. Für Produktion das Image auf
einen unveränderlichen Tag pinnen — per Commit-SHA oder Release-Tag in
`locker-backend/.env`:

```bash
BACKEND_IMAGE_TAG=<github_sha>
```

```bash
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d --force-recreate
```

Die laufende Version ist über `GET /api/identify` als `version` abfragbar.

### MQTT-Authentifizierung

Der Mosquitto-Broker authentifiziert Clients gegen die Laravel-API
(`mosquitto-go-auth`). Die Konfiguration wird aus dem Template erzeugt:

```bash
just setup-mqtt
```

Ohne `just`: `mosquitto.conf` aus dem Beispiel kopieren und in den
Webhook-URIs `mosq_secret=<MOSQ_HTTP_PASS>` eintragen, dann den
Mosquitto-Container neu starten.

### Admin-Benutzer anlegen

```bash
docker compose exec app php artisan filament:user
```

Das Admin-Panel ist unter `https://<deine-domain>/admin` erreichbar.

## Monitoring

- **Health-Endpoint**: `GET /up` (Laravel)
- **MQTT-Listener**: meldet Liveness per Heartbeat im Cache;
  `php artisan mqtt:health` ist der Docker-Healthcheck des
  `mqtt-listener`-Containers. Ein `autoheal`-Sidecar startet unhealthy
  Container automatisch neu. Hinweis: `autoheal` nutzt die Docker-Restart-API —
  Restarts erscheinen in den `autoheal`-Logs, nicht im `RestartCount`.
- **Status-Polling**: `php artisan locker:poll-status` überwacht die
  Schließfach-Status kontinuierlich (separater Container)

## Locker Client am Standort

Der Locker Client läuft als Docker-Container auf einem Raspberry Pi
(3/4/5 oder Zero 2 W, Raspberry Pi OS Lite 64-bit):

- Image: `ghcr.io/open-locker/locker-client:latest`
- Benötigt `config/locker-config.yml` und eine `.env` mit
  `PROVISIONING_TOKEN`
- Verbindet sich per MQTT mit dem Backend und steuert die Schlösser per
  Modbus (TCP oder RTU)

Empfohlene Hardware: siehe
[Stückliste](https://github.com/Open-Locker/Open-Locker/blob/main/docs/Bill-of-Materials.de.md).

## Hosting-Optionen

- **Self-Hosting**: alles selbst betreiben — volle Kontrolle, keine
  Softwarekosten
- **Gehostetes Backend**: wer nicht selbst hosten möchte, kann das zentrale
  Backend hosten lassen — siehe [Angebot auf der Website](/#hosting)
