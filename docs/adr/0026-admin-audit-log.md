# ADR-0026: Admin audit log backed directly by the event store

## Status

Proposed

## Date

2026-06-24

## Context

Admins need a general-purpose audit log to answer "who did what, and when"
across the system (compartment access, locker/device lifecycle, role and
permission changes, terms acceptance). See issue #109.

The backend already uses event sourcing (`spatie/laravel-event-sourcing`).
Every domain change is persisted to the `stored_events` table with
`event_class`, `event_properties` (frequently including `actorUserId`),
`aggregate_uuid`, `meta_data`, and `created_at`. This is effectively a complete,
append-only audit trail that already exists — the missing piece is a curated,
human-readable admin-facing view over it.

Constraints and assumptions:

- The admin UI is Filament v5; this feature is admin-panel only.
- Not every stored event is meaningful for an audit log. High-volume telemetry
  events (`HeartbeatReceived`, `DeviceEventReceived`, `CommandResponseReceived`)
  are noise for "who did what" and would dominate the table.
- Not every event has an actor. System- and device-originated events have no
  `actorUserId`; the actor column is legitimately blank for those.
- `meta_data` is currently empty for stored events.
- No API, MQTT, or Modbus contract is affected — this is read-only and
  backend-internal.

## Decision

Ship a first version of the audit log as a **read-only Filament view that
queries the `stored_events` table directly**, with:

1. **No new read model / projector in v1.** The audit log reads the event store
   directly rather than maintaining a dedicated audit projection or table.
2. **A curated whitelist of admin-visible event classes.** Telemetry/chatter
   events are excluded; only human-meaningful domain events are shown.
3. **Per-event human-readable rendering.** Each whitelisted event class maps to
   a one-line description (e.g. "User #12 opened compartment A3"), resolving
   `actorUserId` to a user where present.
4. **Filtering over per-type tabs.** A single unified log with filters (event
   type, actor, locker/aggregate, date range), optionally grouped into a small
   number of high-level category tabs (Access, Devices/Lockers, Admin, Terms)
   rather than one tab per event class.

## Rationale

The event store is already the source of truth for the audit trail, so reading
it directly gives a complete, trustworthy log with near-zero new data
plumbing — the right altitude for a "first version." A dedicated projection
would add write-path complexity and a parallel copy of data we already have,
with no proven need yet. Curation and human-readable rendering are where the
real value is, so v1 invests effort there. Filtering scales to 35+ (and growing)
event classes in a way per-type tabs do not.

## Alternatives Considered

### Alternative A: Dedicated audit-log projector + read model table

- Pros: query-optimized schema, denormalized actor/target columns, independent
  of event-store internals, easy retention/archival policy.
- Cons: adds a projector and migration to maintain; duplicates data already in
  `stored_events`; needs a replay to backfill; premature for a first version.
- Why not chosen: no demonstrated performance or retention need yet. Revisit if
  `stored_events` growth or query latency makes direct reads impractical.

### Alternative B: One Filament tab per event type

- Pros: trivial per-type filtering.
- Cons: 35+ event classes and growing; most tabs sparse or empty; poor UX; no
  cross-type queries.
- Why not chosen: filters provide the same selectivity and combine across types.

### Alternative C: Show raw `stored_events` with no curation/whitelist

- Pros: zero per-event mapping work.
- Cons: telemetry events drown out meaningful actions; raw JSON properties are
  unreadable for admins; not actually an "audit" view.
- Why not chosen: defeats the purpose of the feature.

## Consequences

### Positive

- Complete audit trail available to admins with minimal new infrastructure.
- Single source of truth — the log cannot drift from actual domain events.
- Clear, documented policy for what is included/excluded and why.

### Negative

- The raw event store (`stored_events`) becomes a UI read dependency; its shape
  is now coupled to the audit view's queries.
- Actor column is blank for system/device-originated events (expected, not a
  bug).
- Adding a new domain event requires adding a description mapping (and a
  whitelist entry) for it to appear meaningfully in the log.

### Risks

- **Query performance / table growth:** `stored_events` grows unbounded.
  Mitigation: index-backed filters (event_class, aggregate_uuid, created_at are
  indexed) plus an always-available **date-range filter** and category tabs, so
  admins query a bounded, recent slice rather than the full table. We keep
  standard **numbered pagination** (Filament's default) for its admin-control
  benefits (page jumps, total counts, shareable position); its offset/count cost
  is acceptable because the working set is filtered, and an audit lookup is not
  latency-critical ("the admin can wait"). Cursor pagination was considered to
  make cost independent of table size, but rejected for v1 as unnecessary
  complexity given the filtered access pattern. Escalation path: switch to
  cursor pagination, or introduce a dedicated projection (Alternative A), if
  reads degrade. Loading states are handled natively by Filament/Livewire — no
  custom loader is needed.
- **Sensitive data exposure:** the log surfaces actor + action history.
  Mitigation: gate access behind an admin role/permission (RBAC, ADR-0021);
  whitelist controls what event detail is rendered.
- **Whitelist drift:** new events silently absent from the log. Mitigation:
  document the whitelist in one place and review it when adding events.

## Rollout / Migration

No migration. Ship as a read-only Filament page/resource over `stored_events`
behind an admin permission. Start with the curated whitelist and category
filters; iterate on event descriptions. If event-store growth or query latency
becomes a problem, supersede this ADR with a dedicated audit projection.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs:
- Related issues: #109
- Related docs: ADR-0021 (role-based access control)
