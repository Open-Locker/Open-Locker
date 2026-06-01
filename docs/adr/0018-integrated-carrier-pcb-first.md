# ADR-0018: Prefer an integrated carrier PCB before a fully custom controller PCB

## Status

Proposed

## Date

2026-06-01

## Context

Open-Locker currently has a validated Raspberry Pi based locker controller that
uses a USB-RS485 adapter and the Waveshare `Modbus RTU Relay (D)` board. The
repository also contains Open-Locker connection-board KiCad projects that
simplify field wiring between locks, relay channels, digital inputs, power, and
RS485.

The current setup works, but it still requires several separate modules and a
fair amount of wiring knowledge. A fully custom Open-Locker PCB could combine
these functions into one board, but that would also move Open-Locker into custom
relay-drive electronics, power electronics, protection design, manufacturing
test, and field safety responsibility.

## Decision

For the next hardware simplification step, Open-Locker should design and
validate an integrated carrier PCB instead of a fully custom all-in-one
controller PCB.

The carrier PCB should keep the proven off-the-shelf controller and I/O boards
as replaceable modules, while reducing field wiring and making the cabinet build
repeatable. The recommended first target is:

- 12 V cabinet power input with fuse and reverse-polarity protection
- 12 V distribution for lock power
- a 12 V to 5 V controller power module or footprint
- RS485 A/B/GND distribution and daisy-chain terminals
- labeled connectors for up to eight locks and eight feedback switches
- flyback diode placement per lock channel
- mounting and connector strategy for the supported relay/input board
- clear silkscreen labels for every field connection
- test pads or status points for power and RS485 diagnostics

The carrier PCB must preserve the current software/hardware support boundary:
the supported relay strategy still depends on verified hardware-timed relay
flash behavior from the Waveshare `Modbus RTU Relay (D)` or a separately
validated equivalent.

A fully custom all-in-one controller PCB can be reconsidered after the carrier
board has been validated in a real cabinet and the project has enough build
volume to justify custom electronics engineering, manufacturing tests, and
support obligations.

## Rationale

The carrier PCB removes the most visible pain for builders: unclear and
repetitive field wiring. It does that without replacing the supported relay
timing behavior, RS485 behavior, and digital-input behavior that Open-Locker has
already validated in software and documentation.

This keeps the first hardware iteration small enough to review and test. A
fully custom controller may reduce part count later, but it should not be the
first simplification step because it changes too many reliability and support
variables at once.

## Alternatives Considered

### Alternative A: Keep only the existing connection board

- Pros:
  - lowest engineering risk
  - already represented in the repository as KiCad projects
  - keeps all active electronics on purchased modules
- Cons:
  - still requires many separate wires and modules
  - less beginner-friendly during assembly
  - more cabinet-specific interpretation is needed
- Why not chosen:
  - it does not fully address the goal of making hardware assembly easier for
    non-electronics builders

### Alternative B: Build a fully custom all-in-one Open-Locker controller PCB now

- Pros:
  - potentially cleanest physical installation
  - fewer purchased boards and fewer internal cables
  - could eventually reduce unit cost at higher volume
- Cons:
  - highest engineering and validation risk
  - requires custom relay, input, RS485, power, protection, and firmware design
  - moves more safety and reliability responsibility onto Open-Locker
  - low-volume unit cost may be worse once engineering and rework are included
- Why not chosen:
  - the current project stage benefits more from repeatability and validation
    than from maximum integration

### Alternative C: Use the planned ESP relay/input board without a carrier PCB

- Pros:
  - fewer modules than the Raspberry Pi setup
  - uses an off-the-shelf board with relays, digital inputs, Ethernet, and RS485
  - aligns with ADR-0005
- Cons:
  - still leaves lock wiring, power distribution, cabinet connectors, and labels
    to the installer
  - does not solve the physical documentation gap by itself
- Why not chosen:
  - this is a good controller option, but it still needs a repeatable wiring
    and mounting layer

## Consequences

### Positive

- lowers the wiring and assembly burden without replacing proven electronics
- keeps the relay/input module replaceable if a board fails or supply changes
- reduces custom electronics risk for early Open-Locker builds
- creates a clearer documentation target for pinouts, labels, and cabinet wiring
- allows the same physical wiring concept to support Raspberry Pi and ESP based
  controller profiles

### Negative

- still uses multiple boards instead of one fully integrated PCB
- may not minimize unit cost at high production volume
- requires another KiCad design iteration and hardware validation cycle

### Risks

- field builders may assume the carrier board makes wiring polarity and power
  sizing automatic
- connector labels could become misleading if they are not verified against the
  schematic, PCB layout, and cabinet harness
- the carrier PCB could be overdesigned before the current cabinet wiring is
  fully documented

Mitigations:

- document logical connections first, then exact pinouts after schematic review
- validate the first board on a bench with current-limited power before using
  real locks
- keep high-voltage mains power outside the Open-Locker PCB
- require an electrical review before ordering assembled prototypes

## Rollout / Migration

- document the existing connection-board projects and their intended use
- create a hardware integration concept for the carrier PCB
- derive a schematic from the validated eight-compartment wiring path
- order a small bare-PCB prototype batch first
- bench-test power, RS485, relay channels, digital inputs, and one lock channel
- test a full eight-compartment cabinet
- update the BOM and ADR status after validation

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related docs:
  - `docs/Hardware-Integration-Concept.md`
  - `docs/Bill-of-Materials.md`
  - `docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`
  - `docs/adr/0005-esp-build-controller-board-selection.md`
  - `hardware/README.md`
  - `locker-client/docs/WAVESHARE_INTEGRATION.md`
  - `https://www.waveshare.com/modbus-rtu-relay-d.htm`
  - `https://www.waveshare.com/esp32-s3-eth-8di-8ro.htm`
  - `https://jlcpcb.com/`
