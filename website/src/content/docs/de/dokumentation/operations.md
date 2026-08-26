---
title: Betrieb
description: Produktions-Deployment des Backends, Locker Client am Standort, Monitoring und Hosting-Optionen.
sidebar:
  order: 4
---

## Cloud-Backend deployen

Das Backend läuft als Docker-Compose-Stack auf einem zentralen Server (VPS
oder Cloud-Instanz). Ein eigenständiges Deployment ergänzt den gepflegten
Traefik-Edge:

```bash
cd locker-backend
docker compose \
  -f docker-compose.prod.yml \
  -f docker-compose.prod.traefik.yml \
  up -d
```

Zuvor `APP_DOMAIN`, `REVERB_DOMAIN`, `MQTT_DOMAIN` und `ACME_EMAIL` setzen.
Öffentlich erreichbar sind HTTPS auf Port 443 und MQTTS auf Port 8883.
Mosquitto-Port 1883 bleibt unverschlüsselt im Docker-Netz und wird in
Produktion nicht veröffentlicht.

Für Coolify v4 das Git-basierte **Docker Compose** Build Pack verwenden und
**Docker Compose Location** auf
`/locker-backend/docker-compose.prod.coolify.yml` setzen. Die Entry-Datei lädt
den Basis-Stack per Compose `extends`; Coolifys ähnlich benanntes Custom
Compose Override konfiguriert die Coolify-Infrastruktur und ist kein
Anwendungs-Overlay. Der verwaltete Traefik-Proxy muss einen TCP-Entrypoint
`mqtts` auf Port 8883 veröffentlichen; der Adapter routet
`HostSNI(MQTT_DOMAIN)` darüber zu Mosquitto-Port 1883. Eine normale
HTTPS-Domain-Route oder direkte Portfreigabe sichert MQTT nicht ab.

Die vollständigen Anleitungen für Standalone und Coolify einschließlich
Firewall, DNS, Zertifikaten und Smoke Tests stehen im
[Installationsleitfaden](https://github.com/Open-Locker/Open-Locker/blob/main/docs/Installation.md).

### Image-Version pinnen

Für Beta- und Produktions-Deployments das Image in `locker-backend/.env` auf
einen unveränderlichen Release-Tag pinnen; `latest` nicht deployen:

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

Locker Clients verwenden `mqtts://<mqtt-domain>:8883` und prüfen öffentliches
Zertifikat und Hostnamen. Vor der Abnahme einen authentifizierten MQTT-Roundtrip
über Port 8883 testen und bestätigen, dass Port 1883 von außerhalb des
Docker-Hosts nicht erreichbar ist.

Auf allen Backend-Instanzen muss derselbe gültige Laravel-`APP_KEY` gesetzt
sein. Das Backend leitet daraus einen domain-separierten HMAC-Subkey für
Provisionierungs-Tokens ab; ein zusätzliches Provisionierungsgeheimnis ist
nicht erforderlich. Eine `APP_KEY`-Rotation invalidiert offene, noch nicht
verbrauchte Provisionierungs-Tokens, für die anschließend neue Tokens
ausgestellt werden müssen. Bereits provisionierte Geräte verwenden ihre
MQTT-Zugangsdaten weiter.

### Admin-Benutzer anlegen

Vor dem ersten Deployment `ADMIN_EMAIL` setzen oder ausführen:

```bash
docker compose exec app php artisan first-admin:create admin@example.com
```

Der Mailversand muss funktionieren, damit der Admin ein Passwort setzen kann.
Das Admin-Panel ist unter `https://<deine-domain>/admin` erreichbar.

## Monitoring

- **Health-Endpoint**: `GET /up` (Laravel)
- **MQTT-Listener**: meldet Liveness per Heartbeat im Cache;
  `php artisan mqtt:health` ist der Docker-Healthcheck des
  `mqtt-listener`-Containers. Ein `autoheal`-Sidecar startet unhealthy
  Container automatisch neu. Hinweis: `autoheal` nutzt die Docker-Restart-API —
  Restarts erscheinen in den `autoheal`-Logs, nicht im `RestartCount`.
- **Offline-Erkennung**: Der Scheduler führt
  `php artisan locker:detect-offline` jede Minute aus

## Locker Client am Standort

Der Locker Client läuft als Docker-Container auf einem Raspberry Pi
(3/4/5 oder Zero 2 W, Raspberry Pi OS Lite 64-bit):

- Image: einen unveränderlichen Release pinnen, zum Beispiel
  `ghcr.io/open-locker/locker-client:client-v1.0.0-beta.1`
- Benötigt `config/locker-config.yml` und eine `.env` mit
  `PROVISIONING_TOKEN`
- Verbindet sich per MQTT mit dem Backend und steuert Waveshare-Relais-Boards
  über serialisiertes Modbus RTU

Die Provisionierung im Admin-Panel ausstellen oder neu starten, das Token aus
dem einmaligen Dialog direkt in die `.env` des Clients kopieren und den Client
neu starten. Das Token kann nicht erneut angezeigt werden; bei Verlust muss ein
neues ausgestellt werden. Persistierte Identität, Zugangsdaten,
Runtime-Konfiguration oder Deduplizierungszustand nicht als routinemäßige
Wiederherstellungsmaßnahme löschen.

Empfohlene Hardware: siehe
[Stückliste](https://github.com/Open-Locker/Open-Locker/blob/main/docs/Bill-of-Materials.de.md).

## Hosting-Optionen

- **Self-Hosting**: alles selbst betreiben — volle Kontrolle, keine
  Softwarekosten
- **Gehostetes Backend**: wer nicht selbst hosten möchte, kann das zentrale
  Backend hosten lassen — siehe [Angebot auf der Website](/#hosting)
