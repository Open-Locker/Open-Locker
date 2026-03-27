# Bill of Materials

German version:
[`docs/Bill-of-Materials.de.md`](Bill-of-Materials.de.md)

This document lists a suggested Bill of Materials (BOM) for an Open-Locker
test cabinet build.

It reflects the hardware used in the current Raspberry Pi based setup and
documents the cabinet-independent electrical parts first. An ESP-based build is
planned, but it is not yet built or validated.

## Scope

This BOM focuses on:

- controller and communication hardware
- relay and lock control hardware
- wiring, connectors, and power distribution
- optional parts that simplify assembly and cabinet expansion

This BOM intentionally does not cover cabinet-specific mechanical integration,
for example:

- how locks are mounted into a wooden cabinet
- how locks are mounted into a metal locker
- drilling, brackets, sheet metal work, or reinforcement

Those details depend heavily on the cabinet type and should be documented
separately once there is a repeatable cabinet design.

## General Notes

- Supplier links are examples only. Equivalent parts from other suppliers are
  acceptable unless a specific part is marked as required.
- We currently do not have affiliate relationships with any of the suppliers
  referenced in this document.
- Use neutral technical names when sourcing parts. Marketplace titles often
  contain marketing language that is not useful for documentation.
- The Modbus relay board must be the Waveshare board listed below because the
  current implementation depends on verified Waveshare-specific flash and
  digital-input behavior. See
  [`docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`](adr/0004-waveshare-hardware-flash-and-supported-boards.md).
- Cable lengths depend on cabinet size and wire routing.
- Power supply sizing depends on the number of compartments, locks, and relay
  boards in the final cabinet.
- This BOM is a documented example of how the project was built. It is not a
  guarantee for a specific supplier, brand, or part revision.

## Build Profiles

### Raspberry Pi Based Build

This is the currently used and validated build profile.

### ESP-Based Build

This build profile is planned, but not yet built or validated.

At a high level, the cabinet-side hardware is expected to stay similar:

- lock hardware
- relay hardware
- wiring
- connectors
- power distribution

The controller stack will likely change, so the controller-specific BOM for the
ESP-based variant is still `TBD`.

## Recommended Connection Board

We recommend using the Open-Locker connection board from the repository to make
the cabinet wiring easier.

Recommended variant:

- [`hardware/connection-board-cut-out_3_5`](../hardware/connection-board-cut-out_3_5)

This is the 3.5 mm terminal variant. It is easier to wire and service than
direct point-to-point wiring.

Per Modbus board / connection board, this design typically uses:

- 10x 2-pin PCB screw terminal connectors
- 13x 4-pin PCB screw terminal connectors

Example connector set:

- 3.5 mm PCB screw terminal connectors
  [example link](https://de.aliexpress.com/item/1005008051970362.html)

PCB manufacturing reference:

- We ordered our PCBs from [JLCPCB](https://jlcpcb.com/).

## Common Components

These parts are shared by both build profiles unless noted otherwise.

| Item | Typical quantity | Notes | Example link |
| --- | --- | --- | --- |
| 8-channel Modbus RTU relay board with digital inputs | 1 per up to 8 compartments | Required part. Use the Waveshare `Modbus RTU Relay (D)` board because the current software uses its flash and digital-input features. Each compartment needs one relay output and one digital input. | [Waveshare relay board](https://www.amazon.de/dp/B0CRKPYVSN) |
| 12 V cabinet lock with feedback switch | 1 per compartment | Use a lock variant with an integrated status or detection switch if possible. Exact mounting depends on the cabinet material and door design. | [Example A](https://www.amazon.de/dp/B07B9WMKG2), [Example B](https://www.amazon.de/dp/B071WBDFZR) |
| 12 V DC power supply | 1 per cabinet | Size the power supply for the maximum number of simultaneously active locks plus controller and relay board overhead. | [Example PSU](https://www.amazon.de/dp/B07GFFG1BQ) |
| Internal cabinet wire | as needed | Choose the wire length based on cabinet geometry and routing paths. | [Example wire](https://www.amazon.de/dp/B0BHSVC7HP) |
| Ferrules, 0.34 mm2 | as needed | Recommended for clean and reliable termination on screw terminals. | [Example ferrules](https://www.amazon.de/dp/B0DJ759X65) |
| 2-pin plug connectors, 2.5 mm pitch | optional | Optional. Can be replaced with direct soldered wire connections if preferred. | [Example connectors](https://www.amazon.de/dp/B07QM13SRX) |
| Panel-mount DC barrel jack, 5.5 x 2.1 mm | 1 per cabinet | Useful for bringing external 12 V power into the cabinet enclosure. | [Example DC jack](https://www.amazon.de/dp/B0F24DFZHF) |
| 4-pole GX16 connector set | optional | Useful when electrically linking multiple cabinets together. | [Example GX16 connector](https://www.amazon.de/-/en/Aiqeer-Aviation-Thread-Connector-Female/dp/B09WXZNKXN/) |
| Connection board, 3.5 mm terminal variant | as needed | Recommended to simplify cabinet wiring. Use the design from `hardware/connection-board-cut-out_3_5`. | [Repository design](../hardware/connection-board-cut-out_3_5) |
| 3.5 mm PCB screw terminal set for the connection board | 1 set per connection board | Typical population: 10x 2-pin and 13x 4-pin connectors per board. | [Example terminal set](https://de.aliexpress.com/item/1005008051970362.html) |
| Short-run cabinet wire between connection board and relay board | as needed | Speaker wire or similar stranded wire is acceptable for short runs. Use wire suitable for up to 12 V / 2 A for the intended path. | No fixed supplier |

## Raspberry Pi Specific Components

These parts are only required for the currently used Raspberry Pi based build.

| Item | Typical quantity | Notes | Example link |
| --- | --- | --- | --- |
| Raspberry Pi 4 or 5 | 1 per cabinet controller | The current build uses a Raspberry Pi 4 with 4 GB RAM. | No fixed supplier |
| USB to RS485 adapter | 1 | Used to connect the Raspberry Pi to the Modbus RTU relay board. | [Waveshare USB to RS485 adapter](https://www.amazon.de/dp/B0B87D9LNC) |
| 12 V to 5 V DC-DC converter | 1 | Used to power the Raspberry Pi from the cabinet's 12 V supply. | [Example DC-DC converter](https://www.amazon.de/dp/B09PFV3SWN) |

## ESP-Based Build Status

The ESP-based build is not yet finalized.

For now, treat the following as stable:

- lock hardware
- relay board requirement
- connection board recommendation
- internal cabinet wiring approach
- basic 12 V power distribution

The following items still need a dedicated design and validation step:

- ESP module selection
- controller carrier or interface board
- RS485 interface strategy
- controller power conversion and protection
- service and update workflow

Until that work is complete, this document only provides a validated BOM for
the Raspberry Pi based build.

## Cabinet-Specific Items Not Covered Here

The following topics are intentionally excluded from this BOM because they
depend on the exact cabinet:

- lock brackets and mounting plates
- drilling templates
- door reinforcement
- mounting hardware for wooden cabinets
- mounting hardware for metal lockers
- enclosure cut-outs and finishing work

Document those items in a cabinet-specific build guide once a repeatable
cabinet design exists.

## Disclaimer

This BOM is provided as a practical reference for the Open-Locker project.
Parts and suppliers may change over time. The project does not provide warranty
or supplier guarantees for the listed parts.

## Feedback

If you build an Open-Locker cabinet based on this BOM, we would appreciate your
feedback.

Useful feedback includes:

- which parts worked well
- which parts were hard to source
- compatible alternative parts or suppliers
- cabinet-specific lessons learned
- corrections for quantities, ratings, or wiring assumptions

Community feedback helps us improve this BOM and make future builds easier to
replicate.
