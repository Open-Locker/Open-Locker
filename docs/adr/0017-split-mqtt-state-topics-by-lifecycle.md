# ADR-0017: Split MQTT state topics by lifecycle

## Status

Accepted

## Date

2026-04-29

## Context

The first AsyncAPI MQTT contract grouped all locker state messages under one
topic:

`locker/{locker_uuid}/state`

The payload then identified the state kind through a `state` field, for example
`heartbeat`, `compartment_snapshot`, or `connection_lost`.

That model works, but the state kinds have different MQTT lifecycle semantics:

- heartbeats are live liveness signals and should not be retained
- compartment snapshots are current state and should be retained
- connection lost signals are LWT/connection signals and should not be retained
  for now

Retain behavior is a property of an MQTT publish on a topic. Mixing retained and
non-retained state kinds on one topic forces additional payload routing and
creates unnecessary special cases around retained snapshot replays and
message-id deduplication.

## Decision

Split locker state into dedicated MQTT topics:

- `locker/{locker_uuid}/state/heartbeat`
- `locker/{locker_uuid}/state/compartments`
- `locker/{locker_uuid}/state/connection`

Topic semantics:

- `state/heartbeat`
  - non-retained
  - liveness signal from the locker-client to the backend
  - payload contains `message_id`, `timestamp`, and `uptime_seconds`

- `state/compartments`
  - retained
  - full current snapshot of all configured compartments
  - payload contains `message_id`, `timestamp`, and `compartments`
  - published after the first successful state poll and afterwards only when at
    least one effective `door_state` changes
  - unreadable configured compartments are reported as `door_state = unknown`

- `state/connection`
  - non-retained for now
  - LWT/connection signal such as unexpected disconnect
  - payload contains `message_id`, `timestamp`, `status`, and `reason`

Because the state type is now encoded in the topic, these payloads do not need a
top-level `state` discriminator.

## Alternatives Considered

### Alternative A: Keep one `locker/{uuid}/state` topic with a `state` field

- Pros:
  - fewer topics
  - one backend subscription for all state kinds
- Cons:
  - mixes retained and non-retained semantics
  - requires conditional validation and routing inside one handler
  - retained snapshot replays need special handling beside heartbeat and LWT
- Why not chosen:
  - lifecycle semantics differ enough that separate topics are clearer and
    reduce special cases

### Alternative B: Split only the retained snapshot topic

- Pros:
  - smaller migration than splitting all state kinds
  - keeps heartbeat and connection signals together
- Cons:
  - still leaves mixed meanings under a generic state topic
  - creates an inconsistent topic taxonomy
- Why not chosen:
  - a complete split is easier to explain, validate, subscribe to, and secure

## Consequences

### Positive

- retain semantics become obvious from the topic
- backend handlers can be smaller and have simpler validation rules
- retained compartment snapshots can be idempotently reapplied without weakening
  deduplication for commands, responses, events, provisioning, or connection
  signals
- future ACLs can authorize state streams more precisely

### Negative

- backend and locker-client implementations must update topic subscriptions and
  publish targets
- existing state-handler code must be migrated away from the single
  `locker/+/state` topic
- current docs and tests referencing `locker/{uuid}/state` need updating

### Risks

- partial migration could leave clients publishing to old state topics while the
  backend listens to new ones
- retained snapshots on the old topic may remain in a broker until explicitly
  cleared

Mitigations:

- update backend and locker-client in one implementation slice
- remove legacy `/state` payload routing because the project is still in alpha
- document the new topics in AsyncAPI and contract tests

## Rollout / Migration

1. Update AsyncAPI and examples to use split state topics.
2. Update locker-client heartbeat, compartment snapshot, and LWT publish topics.
3. Update backend MQTT listener subscriptions and state handlers.
4. Remove legacy support for `event` on state payloads and the old
   `locker/{uuid}/state` topic.
5. Add focused contract tests for the three state topics.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues:
  - [#72](https://github.com/Open-Locker/Open-Locker/issues/72)
  - [#73](https://github.com/Open-Locker/Open-Locker/issues/73)
  - [#74](https://github.com/Open-Locker/Open-Locker/issues/74)
- Related docs:
  - `docs/asyncapi/mqtt.yaml`
  - `docs/adr/0015-define-mqtt-contract-via-asyncapi-and-json-schemas.md`
