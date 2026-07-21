---
title: Reference
description: API specification, MQTT contracts, hardware bill of materials, and further documents.
sidebar:
  order: 7
---

## API

- **OpenAPI specification**: served live by the running backend at
  `/docs/api.json` (Scramble, generated from the controllers)
- **App communication** (REST + WebSocket events, payloads, client flow):
  [App Communication Guide](https://github.com/Open-Locker/Open-Locker/blob/main/docs/app_communication.md)

## MQTT

- **Canonical protocol contract** (AsyncAPI + JSON schemas):
  [`docs/asyncapi/`](https://github.com/Open-Locker/Open-Locker/tree/main/docs/asyncapi)
- Topic structure and message/transaction IDs:
  [ADR-0002](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0002-mqtt-message-id-transaction-id.md)
- Typed outbound publishers in the backend:
  [ADR-0008](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0008-typed-mqtt-publisher-services.md)
- Broker authentication: `mosquitto-go-auth` against `/api/mosq/*`

## Hardware

- **Bill of Materials (BOM)**: see [Hardware](/en/dokumentation/hardware/)
- The Modbus relay board must be the Waveshare board listed there (verified
  flash and digital-input behavior, see
  [ADR-0004](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md))
- KiCad designs: [`hardware/`](https://github.com/Open-Locker/Open-Locker/tree/main/hardware)

## Architecture decision records

All architecture decisions are documented as ADRs:
[`docs/adr/`](https://github.com/Open-Locker/Open-Locker/tree/main/docs/adr)

## Internal documents

Further internal documentation (integration plans, detailed analyses) lives in
the repository under
[`docs/`](https://github.com/Open-Locker/Open-Locker/tree/main/docs).
