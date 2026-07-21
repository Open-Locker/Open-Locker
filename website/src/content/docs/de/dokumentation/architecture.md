---
title: Architektur
description: Wie Backend, Mobile App, MQTT und der Locker Client zusammenspielen.
sidebar:
  order: 3
---

Open Locker ist ein IoT-System aus vier Teilen: einer **Mobile App**, einem
zentralen **Laravel-Backend**, einem **MQTT-Broker** (Mosquitto) und einem
**Locker Client** auf einem Raspberry Pi am Schrank. Das Backend ist die Quelle
der Wahrheit; die Hardware wird ausschließlich vom Locker Client angesteuert.

## Der Weg eines Kommandos

Wenn ein:e Nutzer:in in der App ein Fach öffnet:

```
App → Backend (HTTPS/REST) → MQTT-Broker → Locker Client → Modbus → Schloss
```

Statusmeldungen (z.B. „Tür geöffnet") laufen denselben Weg zurück und werden
der App in Echtzeit zugestellt:

```
Schloss → Locker Client → MQTT-Broker → Backend → Push (WebSocket) → App
```

## Backend

- **Laravel 12** mit **Filament-Admin-Panel** (die einzige servergerenderte
  Oberfläche; alles andere ist API-first)
- **Event Sourcing**: Zustandsänderungen laufen als Events durch Aggregates,
  Projectors (Lesemodelle) und Reactors (Seiteneffekte wie MQTT-Publishing)
- **REST-API** mit live generierter OpenAPI-Spezifikation (Scramble) unter
  `/docs/api.json`
- **PostgreSQL** als Datenbank, Redis für Queues

## Realtime-Kommunikation zur App

- Kommandos laufen über REST (`POST /api/compartments/{id}/open` →
  `command_id`)
- Fortschritt und Türstatus kommen als **WebSocket-Push** (Laravel Reverb +
  Echo) über den privaten Kanal `users.{userId}.compartment-status`
- Fällt die WebSocket-Verbindung aus, pollt die App
  `GET /api/compartments/open-requests/{commandId}` als Fallback

Details und Payloads: [App Communication Guide](https://github.com/Open-Locker/Open-Locker/blob/main/docs/app_communication.md).

## MQTT-Ebene

- **Mosquitto** vermittelt zwischen Backend und Schrank-Standorten
- Clients authentifizieren sich über das Backend (`mosquitto-go-auth` ruft
  `/api/mosq/*` auf) — es gibt keine statischen Broker-Passwörter
- Das Backend publiziert über typisierte Publisher-Services; Topic-Struktur und
  Message-Verträge sind in ADRs festgehalten (siehe
  [Referenz](/dokumentation/reference/))
- Der `mqtt-listener`-Prozess des Backends meldet Liveness per Heartbeat und
  wird bei Ausfall automatisch neu gestartet (siehe
  [Betrieb](/dokumentation/operations/))

## Locker Client (IoT)

- TypeScript/Node-Dienst, läuft als Docker-Container auf einem
  **Raspberry Pi** am Schrank
- Abonniert Kommandos per MQTT und übersetzt sie in **Modbus**-Signale
  (TCP oder RTU) an die Relais-Boards
- Modbus-Operationen sind serialisiert und tolerieren nicht erreichbare
  Boards — nur der Client spricht mit der Hardware, das Backend nie direkt

## Mobile App

- **React Native** (Expo, TypeScript)
- Der API-Client wird per Codegen aus der OpenAPI-Spezifikation des Backends
  erzeugt (RTK Query) — API-Änderungen führen zu regenerierten, typsicheren
  Clients
