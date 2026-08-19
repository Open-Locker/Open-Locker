# ADR-0032: Recover locker-client command responses

## Status

Accepted

## Date

2026-07-27

## Context

The locker client receives transaction-bound commands over MQTT, executes local
side effects, and publishes a final response. MQTT QoS 1 can redeliver packets,
and a network interruption can occur after a physical command has completed but
before its response reaches the broker.

The existing transaction deduplication store records only `in_progress` or
`completed`. A publish attempted while disconnected currently appears
successful, completed commands do not retain their response, and duplicate
commands are silently discarded. A process crash can also leave an
`in_progress` record indefinitely. Repeating an `open_compartment` command
after such a crash would be unsafe because the outcome of the first physical
operation is unknown.

ADR-0002 separates the stable business `transaction_id` from the technical
`message_id` of an individual MQTT publication. ADR-0014 defines persistent
MQTT sessions and reconnect behavior, but neither decision provides a durable
application-level response recovery mechanism. ADR-0030 requires a failed
change-only snapshot publication to remain eligible for a later retry.

## Decision

1. An MQTT publish must fail when no connected MQTT client can accept the
   message. A publish callback without an error is the boundary at which the
   client considers a QoS 1 message accepted by the broker.
2. Transaction handlers return a semantic `CommandResponseBody`; they do not
   publish or persist responses themselves.
3. The command dispatcher stores the complete semantic final response in the
   existing command record before attempting to publish it. The record is
   `completed` and pending delivery until the publish succeeds.
4. A successful publish records a delivery timestamp. A crash between broker
   acknowledgement and that local update can cause a response to be published
   again; this is safe because the backend deduplicates by `transaction_id`.
5. A duplicate completed command never executes its handler again. If its
   record contains a final response, the dispatcher publishes that response
   again. Each publication keeps the original `transaction_id` and receives a
   new `message_id` in accordance with ADR-0002.
6. On startup, persisted `in_progress` records are treated as commands with an
   unknown outcome. The dispatcher does not execute their handlers. It replaces
   each record with a deterministic final error response using the existing
   `UNKNOWN_ERROR` code and queues that response for delivery.
7. The MQTT transport exposes a connection callback. Startup and reconnect
   paths flush pending responses through the dispatcher without polling or
   application-level reconnect timers.
8. Flushes are serialized within the process. Repeated reconnect and duplicate
   events may safely republish a response but cannot repeat the command side
   effect.
9. The existing dedup JSON format is extended additively. Legacy completed
   records without a stored response remain readable and continue to suppress
   command execution, but no response is invented for them.
10. Snapshot publication state advances only after a successful publish, so an
    unchanged snapshot remains eligible after a disconnected publish attempt.

The MQTT payload contract does not change. `UNKNOWN_ERROR` is already a valid
command response code, and response replays intentionally use new technical
message identifiers.

## Alternatives Considered

### Store responses in a separate outbox file

- Pros: Separates delivery state from inbound command deduplication.
- Cons: Creates two persistence sources whose updates must be coordinated.
- Why not chosen: The command record already owns transaction lifecycle state
  and can store its final response without cross-file consistency problems.

### Rely only on persistent MQTT sessions and QoS 1

- Pros: Requires no application persistence changes.
- Cons: Cannot recover a response that was never handed to a connected MQTT
  client and cannot resolve commands left `in_progress` by a process crash.
- Why not chosen: Broker sessions do not cover the local execution-to-publish
  gap.

### Repeat interrupted commands after startup

- Pros: Could complete commands that crashed before reaching the hardware.
- Cons: Could pulse a physical relay twice when the crash occurred after the
  hardware operation.
- Why not chosen: Physical safety takes precedence when the outcome is unknown.

### Add a new interrupted-command error code

- Pros: Makes the recovery condition explicit on the wire.
- Cons: Changes the shared MQTT contract and requires coordinated rollout.
- Why not chosen: `UNKNOWN_ERROR` accurately represents the unknown outcome and
  is already contract compliant.

## Consequences

### Positive

- Completed physical commands are not repeated merely because their response
  was disconnected or lost.
- Final responses survive process restarts and are retried on reconnect.
- Duplicate commands can recover a missing response without repeating side
  effects.
- `open_compartment` and `apply_config` share one finalization path.
- Existing dedup files and MQTT consumers remain compatible.

### Negative

- Command records now retain response data and delivery metadata, increasing
  the dedup file size.
- A response can be delivered more than once when the process crashes after
  broker acknowledgement but before recording delivery.
- Legacy completed records cannot be replayed because their original responses
  were never stored.

### Risks

- The current dedup file write strategy is not fully atomic. General atomic
  persistence remains scoped to issue #168; this change does not introduce a
  second file or broaden that work.
- A malformed legacy file can still prevent startup. This decision preserves
  the existing compatibility behavior rather than introducing a general
  migration framework.
- Replayed responses must remain idempotent at the backend. Existing
  transaction-level response deduplication provides this mitigation.

### Rollout / Migration

- Deploy the updated locker client without changing MQTT topics or schemas.
- Existing records are loaded additively. Persisted `in_progress` records are
  finalized as pending `UNKNOWN_ERROR` responses on first startup.
- Existing completed records without response data continue to block duplicate
  hardware execution and are otherwise left unchanged.
- Validate reconnect and restart recovery before production cutover.

## References

- GitHub issue #171
- GitHub issue #168
- `docs/adr/0002-mqtt-message-id-and-transaction-id-separation.md`
- `docs/adr/0014-locker-client-mqtt-session-and-reconnect.md`
- `docs/adr/0030-batched-door-polling-and-change-only-snapshots.md`
- `locker-client/src/adapters/mqtt/command-dispatcher.ts`
- `locker-client/src/adapters/mqtt/dedup-store.ts`
