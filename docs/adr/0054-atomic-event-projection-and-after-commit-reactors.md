# ADR-0054: Atomic event projection and after-commit reactors

## Status

Accepted

## Date

2026-08-23

## Context

ADR-0033 made all event-sourced projectors synchronous and kept reactors
queued. This provides read-your-writes, but a projector exception can currently
leave the event committed without its read-model update. Replaying the event
store repairs the projection later, even though the original request reported a
failure.

Wrapping the complete synchronous persistence flow in a database transaction
fixes that inconsistency. It also means Spatie's queued
`HandleStoredEventJob` is dispatched while the transaction is open. With
Laravel's default `after_commit = false`, a worker can process that job before
the event and projection commit, or retain side effects after a rollback.

The application writes stored events through two paths:

- aggregate roots call `persist()`;
- MQTT handlers and reactors dispatch `ShouldBeStored` events through Laravel's
  event dispatcher.

Spatie's `AggregateRoot::persistInTransaction()` is not sufficient because it
commits event rows before invoking synchronous projectors.

## Decision

Make both stored-event write paths transactional:

- all application aggregates inherit from a transactional aggregate root that
  wraps the complete `persist()` flow, including synchronous projectors;
- direct `ShouldBeStored` events use an application dispatcher that wraps
  Laravel event dispatch in a database transaction;
- the inherited `persistInTransaction()` behavior is replaced so projectors
  execute before the outer transaction commits.

Replace Spatie's configured stored-event job with an application subclass that
implements Laravel's `ShouldQueueAfterCommit`. This scopes after-commit
dispatching to queued event-sourcing handlers. The global queue connection
configuration remains unchanged.

## Rationale

Central boundaries reduce the chance that a new write path omits the
transaction. The custom stored-event job uses Spatie's supported
`stored_event_job` extension point and Laravel's native after-commit mechanism.
Scoping the behavior avoids changing unrelated jobs, notifications, mailables,
or broadcasts shortly before the beta release.

## Alternatives Considered

### Use Spatie's `persistInTransaction()`

- Pros: built into the package.
- Cons: synchronous projectors run only after the event transaction commits.
- Why not chosen: it does not make the event and projection atomic.

### Wrap every call site independently

- Pros: no shared application abstraction.
- Cons: many existing sites must be audited and future sites can bypass the
  invariant.
- Why not chosen: omission risk is too high for a reliability guarantee.

### Enable `after_commit` on the queue connection globally

- Pros: one configuration change protects every queued operation.
- Cons: changes unrelated queue, notification, mail, and broadcast timing.
- Why not chosen: the beta fix should have the smallest practical blast radius.

### Add a transactional outbox

- Pros: also closes the gap between a successful database commit and a failed
  Redis enqueue.
- Cons: requires another persistence model, publisher process, retries, and
  operational monitoring.
- Why not chosen: valuable post-beta hardening, but disproportionate for the
  current beta scope.

## Consequences

### Positive

- A synchronous projector failure rolls back its stored event.
- Queued reactors are not enqueued for rolled-back events.
- Existing outer transactions compose with the event-sourcing transaction.
- Unrelated Laravel queue behavior is unchanged.

### Negative

- Direct stored events must use the application dispatcher rather than
  Laravel's global `event()` helper.
- Aggregate persistence now always incurs a database transaction, including in
  callers that do not already own one.

### Risks

- This does not provide exactly-once reactor execution; queue redelivery still
  requires idempotent handlers.
- A database commit can still succeed while the subsequent Redis enqueue fails.
- Direct `ShouldBeStored` dispatch can bypass the application dispatcher unless
  review and tests enforce the convention.

## Rollout / Migration

1. Move all application aggregates to the transactional base class.
2. Replace direct stored-event dispatches in MQTT handlers, reactors, and
   commands with the transactional dispatcher.
3. Configure the after-commit stored-event job.
4. Verify projector-failure rollback and commit/rollback queue behavior.
5. Track reactor idempotency, uniform Filament error handling, outbox
   evaluation, and orphan-event monitoring in post-beta issue #230.

Rollback consists of restoring the Spatie aggregate base class, direct Laravel
event dispatch, and the default stored-event job configuration.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- ADR-0033: Synchronous projectors, queued reactors
- GitHub issue #139
- GitHub issue #230: post-beta event-sourcing reliability hardening
- Spatie Laravel Event Sourcing 7.15 `AggregateRoot`, `EventSubscriber`, and
  `HandleStoredEventJob`
- Laravel 12 queued jobs and database transactions
