# ADR-0029: Enforce Modbus RTU inter-frame delay

## Status

Accepted

## Date

2026-07-14

## Context

The locker client polls multiple Waveshare boards sequentially over one shared
RS485 connection. Although the bus actor serializes requests, the
`modbus-serial` RTU driver can send the next request immediately after resolving
the previous response.

Hardware testing with two boards at 9600 baud, 8 data bits, no parity, and one
stop bit reproduced a deterministic failure:

- without a delay, board 1 completed 56 of 56 reads while board 2 timed out on
  all 56 reads
- each board responded normally when read in isolation
- with 10 ms between requests, both boards completed 256 of 256 reads
- with 5 ms between requests, both boards completed 282 of 282 reads

The Modbus serial-line specification requires at least 3.5 character times of
silence between RTU frames. At 9600/8N1 this is approximately 3.65 ms. Node.js
timers and USB serial transport add scheduling uncertainty, so using only the
mathematical minimum does not provide an explicit safety margin.

## Decision

The locker client v2 Modbus RTU driver enforces an inter-frame delay before
every transaction after the first transaction on an open connection.

The delay is calculated from the configured baud rate, data bits, parity, and
stop bits:

- at or below 19200 baud, use 3.5 character times
- above 19200 baud, use the specification's fixed 1.75 ms interval
- add a 1 ms timer safety margin and round up to a whole millisecond

This produces a 5 ms delay for the verified production configuration of
9600/8N1. The driver records transaction completion in a `finally` block so the
delay also applies after timeouts and other failures. Reopening the serial
connection resets the previous-transaction timestamp.

## Alternatives Considered

### Alternative A: Use one fixed delay for every serial configuration

- Pros:
  - simplest implementation
  - 5 ms is verified for the current hardware configuration
- Cons:
  - may be too short at lower baud rates or unnecessarily slow at higher rates
- Why not chosen:
  - the client already supports configurable serial parameters, so timing must
    remain correct when those parameters change

### Alternative B: Add delays in the polling use case

- Pros:
  - limits the change to the observed multi-board read path
- Cons:
  - relay commands, startup failsafe writes, and retries could still violate
    the RTU timing requirement
- Why not chosen:
  - framing is a transport responsibility and must apply consistently to every
    Modbus transaction

### Alternative C: Rely on `modbus-serial` and USB adapter timing

- Pros:
  - no application code or additional latency
- Cons:
  - reproduced deterministic timeouts when switching between two verified
    boards
- Why not chosen:
  - measured behavior shows that serialization alone does not enforce the
    required RTU silent interval

## Consequences

### Positive

- sequential reads across multiple boards are reliable on the verified hardware
- all Modbus operations share one timing policy
- serial configuration changes produce a corresponding timing adjustment
- regression tests can verify timing without physical hardware

### Negative

- every transaction after the first adds a small amount of latency
- driver timing now depends on a monotonic clock and an asynchronous timer

### Risks

- other USB adapters or boards may require more than the calculated interval
- the 1 ms safety margin is based on the verified macOS/USB hardware test and
  must be validated on the production Raspberry Pi

## Rollout / Migration

1. Deploy the driver change to the test Raspberry Pi with the existing
   9600/8N1 configuration.
2. Run a multi-board read soak test and confirm zero timeouts during normal
   polling and relay operation.
3. Increase the safety margin only if the Raspberry Pi test demonstrates a
   platform-specific need.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- `docs/adr/0007-aggregate-state-polling-across-all-configured-modbus-boards.md`
- `docs/adr/0024-locker-client-v2-hexagonal-rewrite.md`
- `locker-client-v2/src/adapters/modbus/modbus-rtu.driver.ts`
- Modbus over Serial Line Specification and Implementation Guide V1.01:
  `https://modbus.org/docs/Modbus_over_serial_line_V1_01.pdf`
