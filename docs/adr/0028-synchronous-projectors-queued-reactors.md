# ADR-0028: Synchronous projectors, queued reactors

## Status

Accepted

## Date

2026-07-03

## Context

Domain state changes flow through `spatie/laravel-event-sourcing`: aggregates
record events, **projectors** build read models from those events, and
**reactors** perform side effects (MQTT publish, Reverb broadcasts,
notifications, command-response handling).

Historically most projectors implemented `ShouldQueue`. Combined with the
event-sourcing queue (`config/event-sourcing.php` → `queue = 'events'`), this
meant read models were rebuilt **only after the `event-worker` processed the
job**. In the Filament admin panel, actions call `resetTable()` immediately
after `Aggregate::persist()`, so the table re-queried the read model **before**
the queued projector had run — admins had to refresh (F5) to see their own
writes. Found during manual QA of the Groups UI (PR #119 / #46, issue #128); the
same pattern affected every event-sourced admin write (compartment access,
group membership, locker banks, etc.).

The relevant mechanics, verified in the Spatie source
(`StoredEvent::handle()` → `Projectionist`):

- Each persisted event is handled in **two phases**. Phase 1
  (`handleWithSyncEventHandlers`) runs all **non-`ShouldQueue`** handlers
  **inline**, in the process that called `persist()`. Phase 2 dispatches
  `HandleStoredEventJob` to the `events` queue, which later runs the
  **`ShouldQueue`** handlers. `ShouldQueue` is therefore a per-handler switch
  between "inline" and "queued".
- Phase 1 always runs **before** phase 2 dispatches, so a synchronous projector
  is guaranteed to run before any queued reactor for the same event.
- Mixed mode was already in use here: `RoleProjector`, `UserRoleProjector`, and
  `TermsProjector` were already synchronous, so synchronous and queued handlers
  already coexisted in production without issue.
- `catch_exceptions` defaults to `false`, so a handler exception propagates
  rather than being swallowed. For a queued handler that fails the job (silent
  retry / `failed_jobs`); for a synchronous handler it propagates into the
  calling request.

## Decision

**Run all projectors synchronously; keep all reactors queued.**

- Remove `ShouldQueue` from the five remaining queued projectors —
  `CompartmentProjector`, `CompartmentAccessProjector`,
  `CompartmentOpenRequestProjector`, `GroupProjector`, `LockerBankProjector` —
  so all eight projectors now build read models inline in the request/worker
  that recorded the event (read-your-writes for Filament and the API).
- Keep `ShouldQueue` on all seven reactors (`MqttReactor`, the three broadcast
  reactors, `CompartmentOpenAuthorizationReactor`, `CommandResponseReactor`,
  `TermsNotificationReactor`) — side effects stay asynchronous on the `events`
  queue.
- **Fail loud.** Leave `catch_exceptions = false`. A projector exception now
  surfaces in the admin's HTTP request (Filament renders an error notification)
  instead of failing silently on the queue. For an interactive admin write, a
  visible failure is preferable to a false success over a drifted read model.
- Restore the natural post-create redirect for groups. `CreateGroup` previously
  overrode `getRedirectUrl()` to return to the list *because* the read model was
  projected asynchronously; with synchronous projection the row exists
  immediately, so it now redirects to the group's **edit** page (to add members
  / grant access), matching Filament's default.
- Make the same guarantee hold for the API/mobile content-note write path
  (PR #130). `CompartmentContentNoteUpdated` is projected by the now-synchronous
  `CompartmentProjector`, so an immediate `GET /api/compartments/accessible`
  reads the new note. `CompartmentService::updateContentNote()` previously
  faked a current response with an in-memory `forceFill()`; it now returns
  `$compartment->refresh()`, i.e. the genuinely persisted read model, rather
  than pretending the projection had already run.

## Alternatives Considered

- **Keep projectors queued; make the UI poll or auto-refresh.** Rejected:
  pushes latency and complexity into every Filament screen, and never gives true
  read-your-writes.
- **`catch_exceptions = true` (swallow projector failures).** Rejected: it
  reproduces today's silent-failure behavior — the request would report success
  while the read model silently drifts. We explicitly want the opposite.
- **Wrap writes in `DB::transaction` + `after_commit` reactors now.** Deferred.
  Spatie's own `AggregateRoot::persistInTransaction()` wraps only the *event
  write*, then runs handlers **after** the transaction commits, so it does **not**
  make event + projection atomic. True atomicity needs our own transaction around
  the whole `persist()`, which in turn forces queued reactors onto `after_commit`
  — a global queue-config change with a much wider blast radius. That is a
  separate reliability-hardening effort (see Consequences), out of scope here.

## Consequences

- **Read-your-writes.** Filament actions (grant/revoke access, add/remove group
  members, create group, locker-bank changes) reflect immediately without a
  manual refresh. Verified with both workers stopped: an event-sourced write
  populated its read-model row inline.
- **Write-path latency moves inline.** Projection work now runs in the request
  (or the recording worker) instead of on the queue. Projectors here are simple,
  deterministic read-model writers, so the added latency is small; worth noting
  for the open-compartment hot path (`CompartmentOpenRequestProjector` runs
  inline in `requestOpen()`).
- **Projector failure = failed request (loud).** Only the genuine error path
  changes; the happy path is identical. Reactors are unaffected — they stay
  async, run after projection, and always see the freshly built read model.
- **Orphaned-event risk on projector failure.** Because there is no transaction
  around `persist()`, a synchronous projector that throws leaves a stored event
  with no read-model update, recoverable via `event-sourcing:replay`. This is
  the same property the three pre-existing synchronous projectors already carry;
  the decision extends, rather than introduces, it.
- **Operability.** Production still requires the `event-worker` running for
  reactors (MQTT/broadcasts/notifications). It is no longer required for read
  models to appear.
- **Follow-up.** Hard atomicity (transactional event-sourced write paths +
  `after_commit` reactors) and a uniform Filament error-notification UX are
  tracked in #139.

## References

- Issue #128 — run projectors synchronously, keep reactors queued
- ADR-0020 — group-based compartment access (the read model whose async
  projection surfaced the F5 problem)
- `locker-backend/config/event-sourcing.php` — `queue`, `catch_exceptions`
- `vendor/spatie/laravel-event-sourcing` — `StoredEvent::handle()`,
  `Projectionist`, `AggregateRoot::persist()` / `persistInTransaction()`
- PR #119 / #46 — Groups UI (where the issue was found)
- PR #130 — Content Notes (API/mobile content-note read-your-writes path)
