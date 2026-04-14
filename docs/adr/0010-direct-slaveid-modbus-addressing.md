# ADR-0010: Use direct slaveId-based Modbus addressing in the locker client

## Status

Accepted

## Date

2026-04-11

## Context

The locker client currently models Modbus boards through a local
`modbus.clients` list with artificial `client.id` values.

At the same time, the runtime `apply_config` contract already identifies
compartments by:

- `id`
- `slaveId`
- `address`

For the currently supported single board type, the extra `client.id` layer does
not add useful domain meaning. It mainly duplicates information that is already
present in the runtime compartment mapping and forces command/polling code to
translate between local client IDs and real Modbus slave IDs.

The project may support more board types in the future, but that is not an
active short-term goal.

## Decision

Open-Locker uses direct `slaveId`-based Modbus addressing in the locker client.

The local base config keeps only bus-level hardware settings such as:

- `modbus.port`
- optional serial defaults like `baudRate`, `dataBits`, `stopBits`, `parity`,
  `timeout`
- `modbus.flashDurationMs`

The client no longer requires a local `modbus.clients` list.

Runtime compartment mapping remains server-managed via `apply_config`, and each
compartment directly declares its `slaveId` and relay `address`.

For legacy startup before any runtime config exists, the client keeps a minimal
single-board fallback and assumes `slaveId = 1`.

## Alternatives Considered

### Alternative A: Keep local client IDs and client list

- Pros:
  - leaves room for per-board local metadata
  - smaller immediate refactor
- Cons:
  - duplicates addressing information already present in runtime config
  - adds translation complexity without current product value
  - keeps a config surface that operators do not really need
- Why not chosen:
  - the current single-board setup is simpler and clearer with direct `slaveId`
    addressing

### Alternative B: Remove both client IDs and legacy fallback immediately

- Pros:
  - strictest runtime-config-only model
  - smallest long-term config surface
- Cons:
  - clients without a received runtime config would lose existing startup
    behavior
  - raises rollout risk during transition
- Why not chosen:
  - keeping a minimal `slaveId = 1` fallback preserves compatibility while still
    simplifying the core design

## Consequences

### Positive

- less local configuration to maintain
- command and polling code align directly with the backend `apply_config`
  contract
- easier reasoning about compartment-to-hardware addressing

### Negative

- less room for board-specific local metadata without a future refactor
- legacy fallback becomes explicitly single-board oriented

### Risks

- assumptions about one board type may need revisiting when heterogeneous boards
  are introduced
- legacy deployments that relied on local multi-client config need migration

Mitigations:

- keep the change scoped to current supported hardware
- reintroduce a richer abstraction only when a real second board model or
  divergent per-board local settings appear

## References

- Related issues:
  - `https://github.com/Open-Locker/Open-Locker/issues/38`
- Related docs:
  - `docs/adr/0009-locker-client-runtime-config-overlay.md`
  - `docs/mqtt_integration_plan.md`
