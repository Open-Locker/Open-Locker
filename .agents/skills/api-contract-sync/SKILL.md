---
name: api-contract-sync
description: Use for Laravel endpoint inputs/outputs, OpenAPI/Scramble, generated RTK Query code, or shared API schemas.
---

Treat backend schemas and the generated mobile client as one contract. Inspect
the actual codegen configuration and scripts, keep resources/documentation
accurate, regenerate rather than hand-edit generated files, then update
consumers. Review `docs/asyncapi/` separately for MQTT contracts. Run backend
tests plus mobile typecheck and focused consumer tests; create an ADR for a
changed integration boundary.
