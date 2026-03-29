# ADR-0007: Aggregate state polling across all configured Modbus boards

## Status

Accepted

## Date

2026-03-29

## Context

The locker client supports multiple Waveshare relay boards on one RS485 bus.
The command path already resolves each compartment to a configured `slaveId` and
channel `address`, so compartment open commands can target different boards.

However, the background state poller still read relay and input status from only
one selected board per polling cycle. In multi-board cabinets this meant:

- status snapshots covered only a subset of configured compartments
- the choice of polled board depended on internal client selection rather than
  compartment mapping
- one "primary" client concept leaked into the implementation even though the
  domain model is compartment-based, not board-primary

The project needs a polling strategy that matches the existing multi-board
compartment mapping model.

## Decision

Open-Locker polls all configured Modbus boards sequentially during each status
cycle and aggregates the results into one MQTT `status_update` payload.

The polling behavior is:

- resolve polling targets from configured Modbus client IDs and slave IDs
- read relay states and digital inputs for each board sequentially on the shared
  RS485 connection
- map each board-local channel back to the configured `compartment_id` using the
  `compartments` configuration
- continue polling other boards when one board times out or is temporarily
  unreachable
- publish one combined state snapshot containing all compartments that were
  successfully polled in that cycle

If no explicit compartment mapping exists, the client falls back to derived
compartment IDs by configured client order and channel index. This fallback
exists for legacy compatibility and is not the preferred multi-board setup.

## Alternatives Considered

### Alternative A: Keep polling a single selected board

- Pros:
  - simpler implementation
  - fewer Modbus reads per polling interval
- Cons:
  - incomplete state snapshots in multi-board cabinets
  - hidden coupling to an arbitrary "primary" client
  - does not reflect the configured compartment mapping model
- Why not chosen:
  - it produces incorrect or partial telemetry for legitimate multi-board
    configurations

### Alternative B: Poll all boards concurrently

- Pros:
  - potentially lower latency per full snapshot
- Cons:
  - the client uses one shared RTU connection and unit ID switching
  - concurrent requests increase the risk of transaction collisions and harder
    to debug bus behavior
- Why not chosen:
  - sequential polling is a better fit for a shared Modbus RTU bus

## Consequences

- MQTT state snapshots now represent all reachable configured boards instead of
  a single board
- compartment status is aligned with the configured `slaveId` and `address`
  mapping
- one unreachable board no longer suppresses telemetry from healthy boards in
  the same polling cycle
- polling a full cabinet requires more Modbus requests per cycle, which may
  increase total snapshot latency on larger installations
- legacy configurations without explicit compartment mapping remain supported,
  but should migrate to explicit mappings for stable multi-board telemetry

## References

- `docs/adr/0006-best-effort-startup-failsafe-for-unreachable-modbus-boards.md`
- `docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`
- `locker-client/src/services/coilPollingService.ts`
- `locker-client/locker-config.yml.example`
