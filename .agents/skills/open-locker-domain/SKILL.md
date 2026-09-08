---
name: open-locker-domain
description: Use when changing Open-Locker architecture, event sourcing, MQTT contracts, cross-component behavior, or deciding whether an ADR is required.
---

# Open-Locker domain

Data flow is `mobile-app → locker-backend → MQTT broker → locker-client → Modbus → physical locker`.
The backend is API-first; Filament is its only server-rendered UI. Backend
domain changes flow through aggregates/events/projectors/reactors rather than
direct read-model mutation. Typed outbound MQTT publishers live in the backend;
Modbus lives only in `locker-client/`.

Before changing architecture, inspect existing ADRs in `docs/adr/`. Create a
new numeric kebab-case ADR when changing an API/schema or integration boundary,
MQTT topic/payload, Modbus strategy, infrastructure/runtime, security,
performance, reliability/operability, or a cross-component flow. Do not rewrite
accepted ADRs; supersede them with a linked ADR.

For contracts, keep `docs/asyncapi/` schemas/examples aligned with backend and
client behavior. Verify the affected component and state the ADR path in the
final summary.
