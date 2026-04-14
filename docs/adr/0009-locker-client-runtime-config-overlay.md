# ADR-0009: Separate locker client base config from server runtime overlay

## Status

Accepted

## Date

2026-04-11

## Context

The locker client currently loads all configuration from one YAML file mounted at
`/config/locker-config.yml`.

That file mixes two different concerns:

- device/bootstrap settings that belong to a deployed client instance
- runtime mapping values that the backend may update via MQTT `apply_config`

The new `apply_config` flow needs the client to accept server-managed
configuration for `compartments` and `heartbeat_interval_seconds`, persist it,
apply it immediately, and acknowledge it with `applied_config_hash`.

Writing server-managed values back into the base YAML would blur the ownership
between local hardware/bootstrap config and backend-driven runtime state.

## Decision

Open-Locker separates locker client configuration into:

- a local base configuration file in `/config/locker-config.yml`
- a server-managed runtime overlay file in the client data directory

The runtime overlay owns only backend-controlled values:

- `mqtt.heartbeatInterval`
- `compartments`

The effective client configuration is the merged result of:

1. base YAML config
2. persisted runtime overlay

The client applies runtime overlay updates immediately after a valid
`apply_config` command by reloading effective config and refreshing the affected
runtime services.

## Alternatives Considered

### Alternative A: Overwrite the base YAML directly

- Pros:
  - only one config file to inspect
  - minimal loader changes
- Cons:
  - server updates would modify local device/bootstrap config
  - harder to reason about ownership and operator intent
  - greater risk of accidental loss of local-only settings
- Why not chosen:
  - backend-driven runtime state should not rewrite the operator-managed base
    configuration file

### Alternative B: Apply config only in memory

- Pros:
  - no extra persisted file
  - simplest write path
- Cons:
  - runtime config would be lost on restart
  - applied state could drift from backend expectations after reconnects or
    crashes
- Why not chosen:
  - the backend expects the client to remember and run the acknowledged config
    beyond one process lifetime

## Consequences

### Positive

- clear ownership boundary between bootstrap and server-managed config
- safer `apply_config` implementation with narrow write scope
- restart behavior stays consistent with the last acknowledged runtime config

### Negative

- one additional persisted config artifact to manage
- config loading logic becomes slightly more complex

### Risks

- partial runtime apply failures could leave overlay and live services out of
  sync
- corrupted overlay data could hide the intended runtime state

Mitigations:

- validate payloads before persisting overlay changes
- reload and re-apply services in a controlled order
- rollback to the previous overlay when immediate apply fails
- treat invalid overlay files as errors in logs and fall back safely where
  possible

## References

- Related issues:
  - `https://github.com/Open-Locker/Open-Locker/issues/38`
- Related docs:
  - `docs/mqtt_integration_plan.md`
  - `docs/adr/0002-mqtt-message-id-and-transaction-id-separation.md`
  - `docs/adr/0008-typed-outbound-mqtt-publisher-services.md`
