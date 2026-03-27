# ADR-0005: Select the ESP32-S3 relay controller for the planned ESP build

## Status

Proposed

## Date

2026-03-27

## Context

Open-Locker currently operates a Raspberry Pi based locker controller. A future
ESP-based build is planned, but the hardware stack is not yet built or
validated.

One reason for evaluating an ESP-based alternative is operational experience
from project testing: the Raspberry Pi based setup has shown reliability
problems in some test scenarios and has occasionally dropped out completely.
The planned ESP variant is therefore also an attempt to reduce controller
complexity and improve stability for cabinet-side operation.

The ESP-based build needs a controller that can:

- provide integrated relay outputs and digital inputs for compartment control
- communicate with additional locker hardware over RS485 when needed
- fit the existing cabinet-side wiring concept as closely as possible
- scale beyond 8 compartments without requiring a completely different
  electrical architecture

The project already relies on the Waveshare `Modbus RTU Relay (D)` board for
the current relay strategy and documents that dependency in
`docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`.

## Decision

For the planned ESP-based build, Open-Locker intends to use the Waveshare
`ESP32-S3-ETH-8DI-8RO` as the primary controller board.

The planned hardware boundary is:

- for builds with up to 8 compartments, the ESP board's onboard 8 relays and 8
  digital inputs are the expected starting point
- for builds with more than 8 compartments, add one or more external Waveshare
  `Modbus RTU Relay (D)` boards over RS485

This is a planning decision for documentation and BOM purposes. It does not yet
mean the ESP-based build is fully validated for production use.

## Rationale

The selected ESP board already combines several capabilities that match the
needs of the planned ESP build:

- 8 relay outputs
- 8 digital inputs
- isolated RS485
- Ethernet connectivity
- wide DC input range

This makes it a strong fit for a compact controller design for smaller
cabinets, while still allowing larger cabinets to reuse the documented
Waveshare Modbus expansion path.

It also supports the project's current direction of evaluating a simpler
controller platform after Raspberry Pi based test setups proved unreliable in
some runs.

## Alternatives Considered

### Alternative A: Use a generic ESP32 module with separate relay and RS485 boards

- Pros:
  - more flexibility in controller board selection
  - potentially lower BOM cost for some builds
- Cons:
  - more integration effort
  - more wiring and enclosure complexity
  - weaker consistency across builds
- Why not chosen:
  - the integrated board reduces hardware design effort for the first ESP build

### Alternative B: Keep the ESP build undefined until implementation starts

- Pros:
  - avoids early commitment
  - allows re-evaluation later
- Cons:
  - BOM and hardware planning remain vague
  - harder to align cabinet wiring expectations early
- Why not chosen:
  - the project needs a documented reference point for planned ESP hardware

## Consequences

### Positive

- the BOM can document a concrete planned ESP controller
- smaller ESP-based cabinets may need fewer separate boards
- larger ESP-based cabinets can extend via the existing RS485 relay strategy

### Negative

- this introduces a planned dependency on a specific Waveshare ESP board
- the board selection may need revision after real-world validation

### Risks

- the planned ESP board may not meet all software, service, or field-debugging
  needs once implemented
- electrical behavior and integration details are not yet validated in the full
  locker workflow

Mitigations:

- keep the ADR in `Proposed` state until the ESP build is implemented and
  tested
- document the >8 compartment expansion path explicitly
- update the BOM and ADR after first hardware validation

## Rollout / Migration

- document the planned controller board in the BOM
- keep the Raspberry Pi build as the only validated build profile for now
- validate the ESP-based build in hardware and software
- update this ADR to `Accepted` or supersede it if the implementation changes

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs:
  - none yet
- Related issues:
  - `https://github.com/Open-Locker/Open-Locker/issues/47`
- Related docs:
  - `docs/Bill-of-Materials.md`
  - `docs/Bill-of-Materials.de.md`
  - `docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`
  - `https://www.waveshare.com/esp32-s3-eth-8di-8ro.htm`
