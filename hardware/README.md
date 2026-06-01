# Open-Locker Hardware Boards

This directory contains Open-Locker PCB and mechanical design files.

The current boards are connection/distribution boards. They simplify cabinet
wiring, but they do not replace the controller or the supported Modbus
relay/input board.

For the recommended hardware direction, see:

- [`docs/Hardware-Integration-Concept.md`](../docs/Hardware-Integration-Concept.md)
- [`docs/adr/0018-integrated-carrier-pcb-first.md`](../docs/adr/0018-integrated-carrier-pcb-first.md)
- [`docs/Bill-of-Materials.md`](../docs/Bill-of-Materials.md)

## PCB Projects

| Path | Purpose | Notes |
| --- | --- | --- |
| [`connection-board-cut-out_3_5`](connection-board-cut-out_3_5) | Recommended connection board for repeatable cabinet wiring. | Uses 3.5 mm screw terminals, which are easier to wire and service in the field. |
| [`connection-board-cut-out_2_54`](connection-board-cut-out_2_54) | Alternate connection board layout. | Uses smaller connector footprints. Pick only if the cabinet harness and connector pitch match. |
| [`mtu-connection-board`](mtu-connection-board) | MTU-specific connection board. | Cabinet/form-factor specific variant. |
| [`mtu-connection-board-large`](mtu-connection-board-large) | Larger MTU-specific connection board. | Cabinet/form-factor specific variant. |
| [`models`](models) | OpenSCAD mechanical helper parts. | Includes lock spacers and PCB mounting adapters. Export STL files from these sources when needed. |

## What the Connection Board Does

The connection board is intended to make the inside of a locker cabinet easier
to assemble and debug.

Logical responsibilities:

- route lock wiring for up to eight compartments
- route feedback switch wiring for up to eight compartments
- place one flyback diode per lock channel
- provide labeled connection points between the cabinet harness and the
  supported relay/input board
- provide power and RS485 connection points for the cabinet wiring path

The board should be treated as a wiring aid. The active relay timing and digital
input behavior still come from the supported relay/input hardware and the
locker-client software.

## Current Supported Relay/Input Board

The validated Modbus relay/input board is:

- Waveshare `Modbus RTU Relay (D)`

Open-Locker currently depends on verified Waveshare hardware flash behavior for
safe lock release pulses. See:

- [`docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md`](../docs/adr/0004-waveshare-hardware-flash-and-supported-boards.md)
- [`locker-client/docs/WAVESHARE_INTEGRATION.md`](../locker-client/docs/WAVESHARE_INTEGRATION.md)

## Before Manufacturing or Wiring

Before ordering PCBs or wiring a cabinet:

1. Open the KiCad project for the selected board.
2. Verify the schematic against the intended lock, relay, power, and RS485
   wiring.
3. Verify connector pitch and orientation against the physical connectors.
4. Confirm trace widths and connector ratings for the lock current.
5. Label the cabinet harness to match compartment numbers, relay addresses, and
   digital input addresses.
6. Bench-test with current-limited 12 V power before connecting all locks.

Exact connector pinouts should be documented in a future wiring guide after the
selected board revision and cabinet harness are validated.
