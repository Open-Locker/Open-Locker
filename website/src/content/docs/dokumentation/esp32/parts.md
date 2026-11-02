---
title: Parts
description: What to buy for an ESP32-controlled locker bank.
sidebar:
  order: 2
---

This is the bench setup the firmware is developed on. No production board has been
chosen, so treat it as a list for a test rig, not a bill of materials for a cabinet.

## Controller parts

| Part | Requirement |
| --- | --- |
| ESP32 D1 mini | ESP-IDF target `esp32`, **4 MB flash**, chip **revision 3.0 or later**. The firmware's image signing needs that revision, and the build sets it as the minimum. The flash layout assumes exactly 4 MB. [Flash the firmware](/dokumentation/esp32/flashing/#connect-the-board) shows how to check both |
| XY-485 converter | Automatic direction, so there is no DE/RE pin to drive. Powered from the ESP32's 3V3 |
| USB data cable | Charge-only cables do not work. The bench board's USB-serial chip is a CH9102 |
| Push button | Normally open, from GPIO 4 to GND. It is the only way to reset a production build |

## Lock panel and locks

| Part | Requirement |
| --- | --- |
| RS485 lock panel | SY-800R, 8 channels, board address 1, with door feedback. The protocol is in [ADR-0071](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0071-rs485-lock-panel-replies-with-active-feedback.md). Only this panel has been tried; others may differ |
| Lock with door feedback | One per channel. The bench uses one, on channel 1. The panel times the release pulse; the firmware only sends commands |
| Panel power supply | The panel has its own supply |

**Not established yet:** the panel's supply voltage and the lock model. Take the voltage
from the seller's documentation for your panel, and check that it matches your locks.

## Shared cabinet parts

Wire, ferrules, connectors and connection boards are the ones in the
[common components](/dokumentation/hardware/#common-components). Ignore the Waveshare
relay board and the Raspberry Pi parts listed there: the lock panel takes their place.
Whether the 12 V locks and the 1N4007 flyback diodes in that list suit this panel has
not been tested.

## Tools you need

- A laptop with Python 3, and either ESP-IDF 6.1 or Docker. See
  [Get the toolchain](/dokumentation/esp32/flashing/#get-the-toolchain).
- A multimeter, to check the panel supply and the polarity before you power on.
- A small screwdriver for the terminals.

## Next

[Wire the parts together](/dokumentation/esp32/wiring/).
