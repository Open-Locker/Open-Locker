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
| ESP32 D1 mini | ESP-IDF target `esp32`, **at least 4 MB flash**, chip **revision 3.0 or later**. See [Choose a chip](#choose-a-chip). [Flash the firmware](/dokumentation/esp32/flashing/#connect-the-board) shows how to check both |
| XY-485 converter | Automatic direction, so there is no DE/RE pin to drive. Powered from the ESP32's 3V3 |
| USB data cable | Charge-only cables do not work. The bench board's USB-serial chip is a CH9102 |
| Push button | Normally open, from GPIO 4 to GND. It is the only way to reset a production build |

## Choose a chip

The firmware needs a classic ESP32 (target `esp32`) at **revision 3.0 or later** with
**at least 4 MB of flash**. It has only run on a revision 3.1 chip with 4 MB. The
[flash layout](/dokumentation/esp32/reference/#flash-layout) uses the first 4 MB, so a
larger chip should run it with the rest unused, but this has not been tried on a board.

| Chip or module | Use it? |
| --- | --- |
| [ESP32-WROOM-32E](https://documentation.espressif.com/esp32-wroom-32e_esp32-wroom-32ue_datasheet_en.html), ESP32-D0WD-V3, ESP32-U4WDH | Yes. These meet the minimum, but have not been tried |
| ESP32 D1 mini | Check first. The bench uses one, but we could not confirm which chip revision and flash size these boards ship with. Run `flash-id` before you rely on one |
| [ESP32-WROOM-32](https://documentation.espressif.com/esp32-wroom-32_datasheet_en.html), ESP32-D0WDQ6-V3 | Avoid for new builds. [Espressif](https://documentation.espressif.com/esp32_datasheet_en.html) marks both not recommended for new designs |
| A chip below revision 3.0 | No. [Image signing needs revision 3.0](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/security/secure-boot-v2.html), and the build enforces it |

### Candidate for a new board

If you are designing a board, look at the
[ESP32-S3-WROOM-1-N16](https://documentation.espressif.com/esp32-s3-wroom-1_wroom-1u_datasheet_en.pdf),
or the 1U version with an external antenna connector. This is a recommendation, not a
decision. **The firmware compiles for the S3 but has never run on one**, and
[more work is known](/dokumentation/esp32-development/#port-to-another-chip).

- **Key rotation:** a classic ESP32 holds one signing key, so a leaked key means a USB
  visit to every unit
  ([ADR-0084](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0084-esp32-firmware-image-signing.md)).
  The S3 [stores up to three keys and can revoke them](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32s3/api-reference/system/ota.html),
  once hardware secure boot is on.
- **No PSRAM:** the firmware does not need it. Modules with 8 MB of Octal PSRAM (`R8`)
  are rated to 65 °C by default, against 85 °C for the N16.
- **16 MB of flash:** leaves room for a bigger journal or image later. Compare current
  prices against the 8 MB version, as we found no reliable ones.
- **External antenna:** the 1U seems the safer choice inside a metal cabinet. This is a
  judgment and has not been tested.

### Compare the chips

Newer chips offer more flash and PSRAM, but not more on-chip RAM.

| Chip | On-chip RAM | Flash | PSRAM | Wi-Fi | Available until at least |
| --- | --- | --- | --- | --- | --- |
| [ESP32](https://documentation.espressif.com/esp32_datasheet_en.html) | 520 KB | Up to 16 MB | Up to 8 MB, 4 MB mapped at a time | 2.4 GHz | 2031 |
| [ESP32-C3](https://documentation.espressif.com/esp32-c3_datasheet_en.html) | 400 KB | Up to 16 MB | None listed | 2.4 GHz | 2033 |
| [ESP32-S3](https://documentation.espressif.com/esp32-s3-wroom-1_wroom-1u_datasheet_en.pdf) | 512 KB | 4, 8 or 16 MB modules | Up to 16 MB | 2.4 GHz | 2033 |
| [ESP32-C6](https://documentation.espressif.com/esp32-c6_datasheet_en.html) | 512 KB | Up to 16 MB | None listed | Wi-Fi 6, 2.4 GHz | 2035 |
| [ESP32-C5](https://documentation.espressif.com/esp32-c5_datasheet_en.html) | 384 KB | Up to 32 MB, shared with PSRAM | Up to 32 MB, shared with flash | Wi-Fi 6, 2.4 and 5 GHz | 2037 |

The years add the commitment to the start date in
[Espressif's longevity table](https://www.espressif.com/en/products/longevity-commitment),
which Espressif can update. The C3 is cheaper, but has less RAM and one 160 MHz core. The
C5 and C6 are only worth it if you need Wi-Fi 6 or, on the C5, 5 GHz.

## Lock panel and locks

| Part | Requirement |
| --- | --- |
| RS485 lock panel | SY-800R, 8 channels, board address 1, with door feedback. The protocol is in [ADR-0071](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0071-rs485-lock-panel-replies-with-active-feedback.md). Only this panel has been tried; others may differ |
| Lock with door feedback | One per channel. The bench uses one, on channel 1. The panel times the release pulse; the firmware only sends commands |
| Power supply | 12 V on the bench. The panel connects to it directly |
| Power converter | Connects the ESP32 to the 12 V supply. Its output must suit the ESP32 board's power pin. See [Power](/dokumentation/esp32/wiring/#power) |

**Not established yet:** the lock model, and the power converter's model and ratings. Check
that your locks are rated for your supply, and use the seller's documentation if you use
another panel.

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
