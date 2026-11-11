---
title: Choose your controller
description: Raspberry Pi or ESP32 — which locker controller fits your build, and how mature each one is.
sidebar:
  order: 1
---

Use the Raspberry Pi for any locker bank that people depend on. The ESP32 firmware
is for a supervised bench: try it, test it and help finish it, but do not install
it in a cabinet in the field.

:::danger[No production unit may ship]
Flash and NVS encryption are off in every build, so the Wi-Fi password, the MQTT
credentials and the setup secrets sit unencrypted on the chip. Anyone who can reach
the unit can read them. Keep a flashed unit attended.
:::

## Raspberry Pi or ESP32?

| | Raspberry Pi (`locker-client/`) | ESP32 (`locker-firmware/`) |
| --- | --- | --- |
| **Status** | The [validated build](/dokumentation/hardware/) | Bench firmware |
| **Lock hardware** | Waveshare Modbus relay board or RS485 lock panel | RS485 lock panel only, one panel per bus |
| **Runs as** | Docker image on Linux | Firmware flashed to an ESP32 with at least 4 MB of flash |
| **Connects with** | [Client configuration and a token](/dokumentation/operations/) | Setup Wi-Fi from a laptop, or USB on bench builds |
| **Updates** | Pull a new image tag | Reflash over USB. Over-the-air updates exist in the code but have not run on a board |
| **Credentials at rest** | On the host's file system | Unencrypted on the chip |
| **Endurance** | In use on test cabinets; the Pi occasionally dropped out in our tests | The 72-hour run has not been done |

Price and power draw have not been measured for either controller, so they are not
compared here.

## What the ESP32 controller does

The ESP32 does for one RS485 lock panel what the Pi client does. It takes
`open_compartment` and `apply_config` commands from the broker, releases the lock,
watches the door and reports state. It speaks the same
[MQTT contract](https://github.com/Open-Locker/Open-Locker/tree/main/docs/asyncapi),
so the backend does not know which of the two it is talking to. A bank is driven by
one or the other, never both
([ADR-0072](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0072-esp32-locker-firmware.md)).

## Maturity of the ESP32 firmware: bench

The automated gates pass without hardware. The checks on real hardware have no
signed-off result yet.

| Hardware group | What it covers | State |
| --- | --- | --- |
| A | Serial bus and panel | No signed-off run. Panel model, address and serial settings were confirmed on the bench |
| B | Commands and journal | No signed-off run |
| C | Network, MQTT and enrollment | No signed-off run. No check covers a verified `mqtts://` connection yet, because the bench broker has no TLS |
| D | State and door events | No signed-off run |
| E | USB commissioning | No signed-off run |
| F | Commissioning over the setup Wi-Fi | Not passed. One bench run on a development build went from an erased unit to an open from the admin panel. The check of the button jumper on GPIO 4 has not run |
| G | Endurance | Not run |
| H | Over-the-air updates | Not run on any board |

The checks themselves are listed under
[Hardware acceptance](/dokumentation/esp32-hardware-acceptance/).

## The path through these pages

1. [Parts](/dokumentation/esp32/parts/): what to get.
2. [Wiring](/dokumentation/esp32/wiring/): connect the ESP32, the converter, the panel and the lock.
3. [Flash the firmware](/dokumentation/esp32/flashing/): build it and put it on the chip.
4. [Bench test](/dokumentation/esp32/bench-test/): check the bus and one lock without a backend.
5. [Commission](/dokumentation/esp32/commissioning/): connect the unit to Wi-Fi and enroll it.
6. [Verify end to end](/dokumentation/esp32/verify/): open a compartment from the admin panel and the app.

Once a unit runs, see [Maintain and reset](/dokumentation/esp32/maintenance/),
[Troubleshooting](/dokumentation/esp32/troubleshooting/) and the
[Reference](/dokumentation/esp32/reference/). To work on the firmware itself, start
at [Develop the ESP32 firmware](/dokumentation/esp32-development/).
