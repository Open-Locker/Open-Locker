# ADR-0030: Batched door polling and change-only snapshots

## Status

Accepted

## Date

2026-07-20

## Context

The locker client polls physical door inputs and publishes a retained compartment
snapshot over MQTT. A five-second interval can miss a complete manual
open/remove/close interaction between two polls.

The current implementation also reads every configured compartment in a
separate Modbus transaction. On an eight-channel board this causes up to eight
requests and, when a board is unreachable, up to eight response timeouts per
cycle.

Polling every 500 milliseconds is fast enough for the physical interaction, but
publishing an unchanged retained MQTT snapshot at the same frequency would add
unnecessary broker and backend traffic. A single transient Modbus failure should
also not replace the last known door state with `unknown`.

ADR-0007 already requires sequential multi-board polling. ADR-0016 requires
retained snapshots after the first effective state and only when the door-state
vector changes. This decision refines how those requirements are implemented.

## Decision

1. Poll door inputs every 500 milliseconds.
2. Read one contiguous Modbus FC02 range per configured board, from its lowest
   configured compartment address through its highest configured address.
3. Poll boards sequentially through the existing serialized bus actor and retain
   the RTU inter-frame delay from ADR-0029.
4. Map each configured compartment address to the corresponding value in its
   board batch.
5. Publish the retained compartment snapshot after the first effective poll and
   only when the complete effective door-state vector changes.
6. Preserve explicit force-publish behavior after `open_compartment` and
   `apply_config`, even when the vector is unchanged.
7. Coalesce a force request that arrives during an active poll into exactly one
   follow-up poll. Regular overlapping interval ticks are skipped.
8. Preserve the last known state for the first two consecutive `unknown` reads
   of a compartment. Publish `unknown` after the third completed poll cycle with
   a consecutive failure. This is approximately 1.5 seconds while the bus is
   healthy enough to complete cycles on schedule, but can take longer when reads
   reach their timeout. If no known state exists, publish `unknown` immediately.
9. Keep the 500-millisecond interval as a code-level constant. It is not
   operator-configurable.

## Alternatives Considered

### Keep five-second polling

- Pros: Lowest Modbus traffic.
- Cons: Can miss normal physical interactions completely.
- Why not chosen: It does not meet the observed locker workflow.

### Poll every compartment separately

- Pros: A failure can be isolated to one address.
- Cons: Multiplies requests, timeout exposure, and snapshot latency per board.
- Why not chosen: Configured addresses can be covered by one contiguous FC02
  range without coupling the bus actor to a specific board size.

### Publish every 500 milliseconds

- Pros: Simple and continuously refreshes the retained message.
- Cons: Produces redundant MQTT and backend work without adding state
  information.
- Why not chosen: ADR-0016 already defines change-only retained snapshots.

### Publish `unknown` on the first failed read

- Pros: Reports communication loss immediately.
- Cons: A single transient RTU error causes visible state flapping and replaces
  a known-good retained state.
- Why not chosen: Three consecutive failures provide a short debounce while
  still surfacing sustained outages.

## Consequences

### Positive

- Manual door interactions lasting at least 500 milliseconds are observable
  during normal healthy-bus operation.
- Each board requires one door-state transaction per cycle.
- An unreachable board contributes one timeout instead of one timeout per
  configured compartment.
- Unchanged states do not generate MQTT or backend traffic.
- Short communication glitches do not immediately overwrite known states.

### Negative

- The Modbus bus is queried more frequently.
- Door-state publication can lag a physical transition by up to approximately
  500 milliseconds under normal conditions.
- Sustained communication loss takes three completed poll cycles to become
  `unknown` when a known state exists and can exceed 1.5 seconds when reads time
  out.

### Risks and rollout

- Validate bus utilization and transition detection with the existing
  three-board 9600/8N1 setup.
- Mac hardware validation completed 60 cycles / 180 board reads with zero
  `unknown` values and 21–28 ms per board. End-to-end MQTT validation projected
  physical state changes from all three boards into the backend read model.
- Repeat the soak test on the target Raspberry Pi before the public beta
  release.
- MQTT disconnect recovery remains tracked separately because a skipped publish
  must not permanently suppress a later state snapshot.

## References

- GitHub issue #166
- `docs/adr/0007-aggregate-state-polling-across-all-configured-modbus-boards.md`
- `docs/adr/0016-retained-compartment-snapshot-and-door-state-persistence.md`
- `docs/adr/0029-enforce-modbus-rtu-inter-frame-delay.md`
- `locker-client/src/application/state-publishing.ts`
- `locker-client/src/adapters/modbus/bus-actor.ts`
