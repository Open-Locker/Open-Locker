---
title: Referenz
description: API-Spezifikation, MQTT-Verträge, Hardware-Stückliste und weiterführende Dokumente.
sidebar:
  order: 6
---

## API

- **OpenAPI-Spezifikation**: wird vom laufenden Backend live unter
  `/docs/api.json` serviert (Scramble, generiert aus den Controllern)
- **App-Kommunikation** (REST + WebSocket-Events, Payloads, Client-Flow):
  [App Communication Guide](https://github.com/Open-Locker/Open-Locker/blob/main/docs/app_communication.md)

## MQTT

- **Kanonischer Protokollvertrag** (AsyncAPI + JSON-Schemas):
  [`docs/asyncapi/`](https://github.com/Open-Locker/Open-Locker/tree/main/docs/asyncapi)
- Topic-Struktur und Message-/Transaction-IDs:
  [ADR-0002](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0002-mqtt-message-id-transaction-id.md)
- Typisierte Outbound-Publisher im Backend:
  [ADR-0008](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0008-typed-mqtt-publisher-services.md)
- Broker-Authentifizierung: `mosquitto-go-auth` gegen `/api/mosq/*`

## Hardware

- **Stückliste (BOM)**:
  [Deutsch](https://github.com/Open-Locker/Open-Locker/blob/main/docs/Bill-of-Materials.de.md) ·
  [Englisch](https://github.com/Open-Locker/Open-Locker/blob/main/docs/Bill-of-Materials.md)
- Das Modbus-Relais-Board muss das dort gelistete Waveshare-Board sein
  (verifiziertes Flash- und Digital-Input-Verhalten, siehe
  [ADR-0004](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md))
- KiCad-Designs: [`hardware/`](https://github.com/Open-Locker/Open-Locker/tree/main/hardware)

## Architecture Decision Records

Alle Architektur-Entscheidungen sind als ADRs dokumentiert:
[`docs/adr/`](https://github.com/Open-Locker/Open-Locker/tree/main/docs/adr)

## Interne Dokumente

Weitere interne Dokumentation (Integrationspläne, Detailanalysen) liegt im
Repository unter
[`docs/`](https://github.com/Open-Locker/Open-Locker/tree/main/docs).
