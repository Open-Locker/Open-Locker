---
name: open-locker-domain
description: Use for Open-Locker architecture, event sourcing, MQTT contracts, cross-component behavior, or ADR decisions.
---

Data flow is `mobile-app → locker-backend → MQTT → locker-client → Modbus → locker hardware`.
The backend is API-first; domain changes use aggregates/events/projectors/reactors,
and physical Modbus communication belongs only to `locker-client/`.

Before architecture-significant work, inspect `docs/adr/`. Create a new ADR for
API/schema or integration boundaries, MQTT contracts, Modbus strategy,
infrastructure, security, performance, reliability, or cross-component changes.
Do not rewrite accepted ADRs; supersede them with a linked ADR.
