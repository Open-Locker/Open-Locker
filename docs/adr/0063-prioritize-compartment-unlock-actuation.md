# ADR-0063: Prioritize compartment unlock actuation

## Status

Accepted

## Date

2026-09-23

## Context

An open command must actuate the configured lock even when the door input
already reports `open`. The input can be wrong because of a damaged sensor or
cable, so it must not suppress the hardware command.

The client previously separated connection establishment from relay actuation.
Both operations used the serialized hardware queue, allowing a queued snapshot
or poll to start between them. A failed sensor operation could then hold the
unlock behind one or more five-second reconnect intervals.

Reading the door before actuation to produce `already_open` has little diagnostic
value: it cannot distinguish a physically open door from a faulty input, and it
adds latency and state coordination to the critical command path.

## Decision

Every valid open command actuates the configured lock without a pre-command door
read.

Each hardware adapter establishes its connection and performs the actuation in
one command-priority queue operation. This applies to both supported hardware
profiles:

- Waveshare Modbus relay boards;
- generic RS485 lock boards.

Door detection starts only after successful actuation:

- observing `open` produces `opened`;
- never observing `open` within the detection window produces `door_jammed`.

New clients do not produce `already_open`. MQTT schemas and backend handlers
continue accepting it for older clients and historical event replay.

## Rationale

Physical actuation is the user-facing requirement and must not wait for a
diagnostic sensor read. Keeping connection and actuation in one queue slot also
prevents lower-priority polling work from entering between them.

Retained snapshots continue reporting the observed input independently.
Closed-to-open transitions without a recent relay pulse continue producing
uncommanded-open events.

## Alternatives Considered

### Read the input immediately before actuation

- Pros: distinguishes a reported pre-existing open level.
- Cons: a failed read or reconnect delays actuation.
- Why not chosen: an unreliable diagnostic must not block opening.

### Classify `already_open` from the polling cache

- Pros: avoids a command-specific hardware read.
- Cons: requires freshness rules and can suppress a real jam when stale.
- Why not chosen: the diagnostic value does not justify the failure modes.

### Require a closed-to-open transition

- Pros: `opened` would represent an observed edge.
- Cons: an already open or initially unknown door would incorrectly time out as
  jammed.
- Why not chosen: post-actuation open-state detection is less misleading when
  the initial state cannot be trusted.

## Consequences

### Positive

- Every valid command reaches the lock regardless of the reported door input.
- No pre-command sensor read can delay actuation.
- Polling cannot start between connection establishment and actuation.
- The behavior is consistent across Waveshare Modbus and RS485 lock boards.

### Negative

- A door that was already physically open is reported as `opened`.
- New clients no longer provide request-specific `already_open` diagnostics.

### Risks

- A sensor stuck at `open` makes commands report `opened`; retained snapshots
  remain the source for diagnosing that input.
- A hardware operation already in progress remains non-preemptible and can still
  delay the command.
- A transport failure after physical actuation can cause the existing retry path
  to repeat the pulse.

## Rollout / Migration

Deploy the locker client and compare backend request, MQTT publication, command
response, and door-detection timestamps. Monitor `opened`, `door_jammed`, and
uncommanded-open events.

No backend schema or stored-event migration is required. Keep compatibility
handlers for older clients and historical events.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none
- Extends: ADR-0061 by making connection establishment and actuation atomic

## References

- Related issues: Open-Locker/Open-Locker#300, Open-Locker/Open-Locker#302
- Related docs: ADR-0016, ADR-0038, ADR-0040, ADR-0051, ADR-0061
