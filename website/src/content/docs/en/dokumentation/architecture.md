---
title: Architecture
description: How the backend, mobile app, MQTT, and the locker client play together.
sidebar:
  order: 3
---

Open Locker is an IoT system made of four parts: a **mobile app**, a central
**Laravel backend**, an **MQTT broker** (Mosquitto), and a **locker client**
on a Raspberry Pi at the cabinet. The backend is the source of truth; the
hardware is driven exclusively by the locker client.

## The path of a command

When a user opens a compartment in the app:

```
App → Backend (HTTPS/REST) → MQTT broker → Locker client → Modbus → Lock
```

Status updates (e.g. "door opened") travel the same path back and are pushed
to the app in real time:

```
Lock → Locker client → MQTT broker → Backend → Push (WebSocket) → App
```

## Backend

- **Laravel 12** with a **Filament admin panel** (the only server-rendered UI;
  everything else is API-first)
- **Event sourcing**: state changes flow as events through aggregates,
  projectors (read models), and reactors (side effects such as MQTT
  publishing)
- **REST API** with a live-generated OpenAPI specification (Scramble) at
  `/docs/api.json`
- **PostgreSQL** as the database, Redis for queues

## Realtime communication with the app

- Commands go through REST (`POST /api/compartments/{id}/open` →
  `command_id`)
- Progress and door state arrive as **WebSocket pushes** (Laravel Reverb +
  Echo) on the private channel `users.{userId}.compartment-status`
- If the WebSocket connection is unavailable, the app polls
  `GET /api/compartments/open-requests/{commandId}` as a fallback

Details and payloads: [App Communication Guide](https://github.com/Open-Locker/Open-Locker/blob/main/docs/app_communication.md).

## MQTT layer

- **Mosquitto** mediates between the backend and cabinet locations
- Clients authenticate against the backend (`mosquitto-go-auth` calls
  `/api/mosq/*`) — there are no static broker passwords
- The backend publishes via typed publisher services; topic structure and
  message contracts are captured in ADRs (see
  [Reference](/en/dokumentation/reference/))
- The backend's `mqtt-listener` process reports liveness via a heartbeat and
  is restarted automatically on failure (see
  [Operations](/en/dokumentation/operations/))

## Locker client (IoT)

- TypeScript/Node service, runs as a Docker container on a **Raspberry Pi**
  at the cabinet
- Subscribes to commands via MQTT and translates them into **Modbus** signals
  (TCP or RTU) for the relay boards
- Modbus operations are serialized and tolerate unreachable boards — only the
  client talks to the hardware, never the backend directly

## Mobile app

- **React Native** (Expo, TypeScript)
- The API client is generated from the backend's OpenAPI specification via
  codegen (RTK Query) — API changes lead to regenerated, type-safe clients
