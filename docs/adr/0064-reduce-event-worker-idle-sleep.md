# ADR-0064: Reduce event-worker idle sleep

## Status

Accepted

## Date

2026-09-23

## Context

Compartment opening keeps MQTT publication on the queued `events` path. The
event worker used Laravel's default three-second idle sleep. When an open
authorization arrived while the worker was idle, that polling interval could
add up to three seconds before the queued reactor ran.

The event worker is intentionally a single process because some derived-event
guards are not safe with concurrent consumers. Scaling it is therefore not an
appropriate latency hotfix.

## Decision

Run the event worker with `--sleep=1` in local, standard production, and Coolify
production Compose configurations.

Keep one event worker, keep MQTT publication queued, and retain the existing
retry and timeout settings.

## Rationale

One second reduces worst-case idle pickup latency without introducing concurrent
event consumers or moving MQTT publication into the request. It avoids the
continuous Redis polling of `--sleep=0`.

## Alternatives Considered

### Use `--sleep=0`

- Pros: lowest polling latency.
- Cons: continuously polls Redis while idle.
- Why not chosen: unnecessary resource use for the hotfix.

### Add another event worker

- Pros: increases throughput as well as responsiveness.
- Cons: can race non-atomic derived-event guards and duplicate permanent event
  history.
- Why not chosen: violates the current single-consumer constraint.

### Publish MQTT synchronously

- Pros: removes queue pickup latency.
- Cons: can publish before commit, blocks HTTP on broker availability, and loses
  the after-commit retry boundary.
- Why not chosen: weakens ADR-0033 and ADR-0054 guarantees.

## Consequences

### Positive

- An idle event worker normally picks up an opening event within one second.
- Event-sourcing and after-commit MQTT guarantees remain unchanged.

### Negative

- The worker polls Redis more often while idle.
- This does not remove delays from an existing queue backlog.

### Risks

- A high event backlog can still delay opening commands because event jobs share
  one ordered queue.

## Rollout / Migration

Redeploy or restart the event-worker service so the new command takes effect.
Compare authorization-to-MQTT timestamps before and after rollout.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: Open-Locker/Open-Locker#300
- Related docs: ADR-0033, ADR-0054
