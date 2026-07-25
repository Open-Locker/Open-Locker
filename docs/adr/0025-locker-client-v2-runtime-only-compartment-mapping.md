# ADR-0025: locker-client-v2 runtime-only compartment mapping

## Status

Accepted

## Date

2026-06-20

## Context

[ADR-0009](0009-locker-client-runtime-config-overlay.md) separates operator-managed
base YAML from server-managed runtime overlay values (`compartments`,
`heartbeat_interval_seconds`).

During the v1-to-v2 transition, `locker-client-v2` still exposed optional
`compartments` and `heartbeatInterval` fields on the base YAML type and kept a
legacy open-compartment fallback: without runtime mapping, compartment `N` mapped
to relay `N-1` on `slaveId = 1`.

That duplicated ownership already decided in ADR-0009 and contradicted the v1
operator guidance that compartment mapping is backend-managed only.

## Decision

In `locker-client-v2`:

1. Base YAML (`locker-config.yml`) contains only hardware/bootstrap settings:
   Modbus bus parameters, MQTT transport settings, and flash duration.
2. Runtime overlay (`.runtime-config-overlay.json`) is the **only** source for
   `compartments` and `heartbeatInterval`.
3. Legacy YAML fields for compartments/heartbeat are ignored if present.
4. The pre-`apply_config` open-compartment heuristic is removed. Without runtime
   mapping, `open_compartment` returns `RUNTIME_CONFIG_NOT_APPLIED`.
5. `getConfiguredSlaveIds()` derives slave IDs only from runtime compartments;
   without mapping it returns `[]`, so startup failsafe skips Modbus boards until
   `apply_config` arrives.

Types are split into `BaseLockerConfig` (YAML) and `EffectiveLockerConfig`
(YAML + overlay).

## Alternatives Considered

### Alternative A: Keep YAML compartments for local dev

- Pros:
  - simpler hardware bring-up without backend
- Cons:
  - two sources of truth for the same data
  - drift between dev and production behavior
- Why not chosen:
  - conflicts with ADR-0009 ownership boundary

### Alternative B: Keep legacy open heuristic (ADR-0010)

- Pros:
  - allows opening lockers before first backend push
- Cons:
  - hides missing runtime config in production
  - behavior differs from backend-managed mapping contract
- Why not chosen:
  - v2 targets strict runtime-config-only operation

## Consequences

### Positive

- single runtime source for compartment mapping in v2
- clearer operator model aligned with v1 documentation
- explicit error when backend config has not been applied yet

### Negative

- local/dev testing requires an `apply_config` step (or overlay fixture)
- fresh devices cannot open compartments until backend provisioning completes

### Risks

- deployments that relied on YAML compartment lists must migrate to backend push
- brief window after first boot where snapshots are empty until apply completes

Mitigations:

- backend should send `apply_config` during locker provisioning/onboarding
- runtime overlay persists across restarts once applied

## Supersedes / Superseded By

- Supersedes: legacy-fallback portion of [ADR-0010](0010-direct-slaveid-modbus-addressing.md) for **locker-client-v2 only** (v1 unchanged)
- Related: [ADR-0009](0009-locker-client-runtime-config-overlay.md), [ADR-0024](0024-locker-client-v2-hexagonal-rewrite.md)

## References

- Related docs:
  - `locker-client-v2/README.md`
  - `locker-client-v2/locker-config.yml.example`
  - `docs/plans/locker-client-v2-rewrite.md`
