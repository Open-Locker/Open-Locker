---
name: api-contract-sync
description: Use when changing Laravel endpoint inputs/outputs, OpenAPI/Scramble documentation, mobile generated RTK Query code, or shared API schemas.
---

# API contract sync

Treat the backend schema and the generated mobile client as one change. Inspect
the actual `mobile-app/openapi-codegen.config.js` and package scripts before
running commands because documentation may lag the configuration. Keep endpoint
documentation and JsonResources accurate, regenerate the client from the
running/available backend using the configured script, then update consumers
without editing generated files manually.

Review `docs/asyncapi/` separately when the change crosses MQTT; OpenAPI and
AsyncAPI are distinct contracts. Run backend tests plus mobile typecheck and
the narrowest relevant consumer tests. If the boundary changes, create or
update an ADR via `open-locker-domain`.
