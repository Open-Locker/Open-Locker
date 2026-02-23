# ADR-0001: Terms versioning with mandatory acceptance gate

## Status

Proposed

## Date

2026-02-23

## Context

The backend must support legally relevant terms (AGB/ToS) that users must
accept before using most domain features. The system also needs:

- a single admin-managed document with a configurable name
- immutable published versions for legal traceability
- per-user acceptance tracking against the currently active version
- API support for mobile clients to read and accept terms
- notification when a new version is published
- auditability for both publication and user acceptance

The project already uses `spatie/laravel-event-sourcing` with stored events,
projectors, and reactors.

## Decision

Implement terms handling as an event-sourced domain with relational read
models:

- one logical `terms_documents` record (configurable document name)
- multiple immutable `terms_document_versions`
- per-user acceptance records in `user_terms_acceptances`
- access gate middleware that blocks protected domain routes until the user
  accepts the currently active terms version
- API endpoints for reading current terms and accepting them
- user state flags returned by `GET /api/user`
- queued email notification on newly published terms versions

Published versions are immutable. Any content change requires a new version.

## Rationale

Event sourcing provides an auditable timeline of publication and acceptance
actions, which is important for legal and compliance use cases. Relational
projections keep runtime API checks fast and simple. A middleware gate enforces
the rule consistently across endpoints without duplicating checks in each
controller.

## Alternatives Considered

### Alternative A: Plain CRUD only (no event sourcing)

- Pros:
  - simpler initial implementation
  - fewer moving parts
- Cons:
  - weaker audit trail and legal traceability
  - harder to reconstruct acceptance/publish history reliably
- Why not chosen:
  - does not meet traceability requirement strongly enough

### Alternative B: Per-endpoint controller checks instead of middleware gate

- Pros:
  - no new middleware class
  - explicit checks in each endpoint
- Cons:
  - duplicated logic and higher regression risk
  - easy to forget checks on new routes
- Why not chosen:
  - central middleware is safer and easier to maintain

## Consequences

### Positive

- full publication/acceptance audit trail in stored events
- consistent enforcement across protected routes
- mobile app can reliably detect and handle pending acceptance state

### Negative

- increased implementation complexity (events, aggregate, projector, reactor)
- additional database tables and API surface

### Risks

- notification fan-out can be heavy for large user counts; mitigate with queued
  notifications and chunking
- route allowlist mistakes could block important actions; mitigate with feature
  tests for allowed and blocked endpoints

## Rollout / Migration

1. Create schema and projections for terms documents, versions, and acceptances.
2. Add event-sourced aggregate, events, projector, and notification reactor.
3. Add terms API endpoints and user resource flags.
4. Add terms acceptance middleware gate with explicit allowlist.
5. Add admin Filament resource for publishing immutable versions.
6. Run feature tests for acceptance flow, gate behavior, and notifications.

Fallback: disable the middleware gate if rollout issues appear, while keeping
terms endpoints and data model intact.

## Supersedes / Superseded By

- Supersedes: N/A
- Superseded by: N/A

## References

- Related PRs: N/A
- Related issues: N/A
- Related docs:
  - `docs/adr/0000-template.md`
