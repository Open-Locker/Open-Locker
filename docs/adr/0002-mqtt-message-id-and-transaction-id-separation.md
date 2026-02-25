# ADR-0002: Separate `message_id` and `transaction_id` in MQTT contracts

## Status

Accepted

## Date

2026-02-25

## Context

Open-Locker uses MQTT with QoS 1 (`at least once`). This can produce duplicate
packet delivery during retries, reconnects, or broker/client restarts.

Current flows already use `transaction_id` for command/response correlation.
That identifier remains stable across one command lifecycle and is used for
business-level idempotency (for example: do not execute one command twice).

However, `transaction_id` does not identify a concrete MQTT packet instance.
For transport-level deduplication across all MQTT message types (`command`,
`response`, `event`, `state`, provisioning), a dedicated technical identifier
is required.

## Decision

Adopt two distinct identifiers in MQTT payloads:

- `transaction_id`: business correlation ID for transaction-bound flows
  (`command`, `response`).
- `message_id`: technical packet/message ID for transport-level deduplication
  on all MQTT messages.

Validation rules:

- `message_id` is required for all MQTT messages.
- `transaction_id` is additionally required for transaction-bound messages.
- Messages that miss required IDs are rejected without side effects.

Dedup rules:

- Technical dedup: by `message_id` in a guard layer before message handlers.
- Business dedup: by `transaction_id` for command lifecycle semantics.

## Alternatives Considered

### Alternative A: Use only `transaction_id`

- Pros:
  - no new field in payloads
  - smaller migration
- Cons:
  - cannot model packet-level duplicates cleanly
  - does not apply naturally to non-transactional messages
- Why not chosen:
  - mixes transport concerns with business correlation

### Alternative B: Use only `message_id`

- Pros:
  - one universal dedup field
- Cons:
  - loses explicit business correlation semantics for command/response flow
  - harder to reason about domain idempotency and tracing
- Why not chosen:
  - weakens command transaction model already used in backend/client

## Consequences

### Positive

- clear separation of transport and domain semantics
- robust dedup across all MQTT communication types
- better observability and debugging of retries/duplicates

### Negative

- payload contract changes across backend and client
- additional guard-layer logic and migration/testing effort

### Risks

- partial rollout may create temporary compatibility mismatches
- inconsistent producer updates can generate rejected messages

Mitigations:

- staged rollout with compatibility window where feasible
- contract tests and integration tests for missing/duplicate IDs

## References

- Related issues:
  - `https://github.com/Open-Locker/Open-Locker/issues/41`
  - `https://github.com/Open-Locker/Open-Locker/issues/42`
- Related docs:
  - `docs/mqtt_integration_plan.md`
