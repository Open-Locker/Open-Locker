---
title: Reference
description: Console commands, button behaviour, limits and flash layout of the ESP32 firmware.
sidebar:
  order: 10
---

Everything here is checked against the firmware source. The MQTT contract itself is in
[`docs/asyncapi/`](https://github.com/Open-Locker/Open-Locker/tree/main/docs/asyncapi).

## Console commands

The console runs on UART 0 over USB, at 115200 baud. Send one command per line, ending in CR, LF or CRLF, of
at most 1024 bytes. A partial line idle for 10 seconds is discarded. Your typing is not echoed, and no reply
repeats a value you sent.

| Command | What it does | Replies | Builds |
| --- | --- | --- | --- |
| `status` | Bus state, enrollment and settings | `ok bus=<state> enrolled=<0\|1> pending=<0\|1> configured=<0\|1>` | All |
| `diagnostics` | Bus state, unlocks on the wire since boot, lowest free heap since boot, unused owner task stack in bytes | `ok bus=<state> unlock_transmissions=<n> min_free_heap=<n> owner_stack_free=<n>` | All |
| `self-test` | Checks the frame encoder. Sends nothing to the panel | `ok self-test passed`, `error self-test failed` | All |
| `query <board>` | Reads the doors at addresses 0 to 31: `c` closed, `o` open, `?` unknown | `ok doors=<32 characters>`, `error query no_valid_reply` | All |
| `unlock <board> <address>` | **Releases that lock**, once, bypassing the journal. Refused once the unit is enrolled or has settings | `ok unlock acknowledged`, `error unlock not_sent`, `error unlock refused`, `error unlock outcome_unknown`, `error unlock disabled` | Release, development |
| `command <json>` | Feeds one MQTT command to the application, as if the broker sent it. Refused once settings are stored | `ok command queued`, `error command not accepted` | Release, development |
| `configure <json>` | What `commission.py --usb` sends | `ok saved`, `error invalid_settings`, `error reset_required`, `error save_failed`, `error setup not accepted` | Release, development |
| `reset-network` | Forgets settings, identity and mapping, then restarts. Keeps the journal | `ok reset requested`, `error reset not accepted` | Release, development |

`<state>` is `connected`, `disconnected` or `unreachable`. A production build answers its four missing commands
like any unknown word: `error invalid command`. A line over the limit gets `error line too long`.

In bench mode, the unit also prints what it would publish: `out response`, `out heartbeat`, `out snapshot`,
`out event` and `out connection`, each followed by JSON.

## Button

A push button from GPIO 4 to GND, sampled every 20 ms, with the internal pull-up.

| Parameter | Value |
| --- | --- |
| Hold for a reset | 10 s to under 30 s, acting on release |
| Hold that is void | 30 s or more |
| Released time before a press counts | 1 s |
| Bounce that is ignored | Under 50 ms |
| Gap between samples that voids a press | Over 100 ms |
| Refused reset is offered again for | 2 s, then logged |

The build refuses strapping, flash, input-only, console and RS485 pins for the button.

## Serial line and timing

These are defaults, changed with `idf.py menuconfig`.

| Setting | Kconfig | Default |
| --- | --- | --- |
| UART | `CONFIG_LC_RS485_UART_NUM` | 2 (1 or 2 allowed) |
| TX pin | `CONFIG_LC_RS485_TX_PIN` | GPIO 17 |
| RX pin | `CONFIG_LC_RS485_RX_PIN` | GPIO 16 |
| Baud rate, parity, stop bits | `CONFIG_LC_RS485_BAUD_RATE`, `CONFIG_LC_RS485_PARITY_*`, `CONFIG_LC_RS485_STOP_BITS` | 9600, none, 1 |
| Reply deadline | `CONFIG_LC_RS485_REPLY_DEADLINE_MS` | 1500 ms |
| Quiet interval that ends a frame | `CONFIG_LC_RS485_QUIET_INTERVAL_MS` | 25 ms |
| Silence after a failed exchange | `CONFIG_LC_RS485_SETTLE_PERIOD_MS` | 3000 ms |
| Failures before a cooldown | `CONFIG_LC_RS485_FAILURES_BEFORE_COOLDOWN` | 5 |
| Cooldown | `CONFIG_LC_RS485_COOLDOWN_MS` | 60000 ms |
| Reset button pin | `CONFIG_LC_RESET_BUTTON_GPIO` | GPIO 4 |

Doors are polled every 500 ms once a mapping is applied. After three failed reads, a door reports as unknown.

## Setup network

| Parameter | Value |
| --- | --- |
| Open for | 10 minutes after power-up, on a unit with no settings and not enrolled |
| Closes | After saving, after 10 minutes, or after 5 failed sessions |
| Reopens | On the next boot, if there are still no settings |
| Name | `OpenLocker-` and 4 random characters, from the label |
| Security | WPA2 with a 16-character passphrase, and Security 2 with a 12-character setup password |
| Stations | One at a time, on channel 1 |
| Address | `192.168.4.1:80` |

The `commission.py` limits on values:

| Value | Limit |
| --- | --- |
| Wi-Fi name | 1 to 32 bytes |
| Wi-Fi password | Empty, or 8 to 63 characters on the setup network and 8 to 64 over USB |
| Broker URL | Up to 192 bytes, `mqtts://` (or `mqtt://` on a development build), default ports 8883 and 1883 |
| Bootstrap user, bootstrap password, token | 1 to 128 bytes each |

## Command error codes

| Code | Meaning |
| --- | --- |
| `MISSING_MESSAGE_ID` | The command has no `message_id` |
| `INVALID_COMMAND` | Bad timestamp, non-object `data`, or a missing field |
| `UNKNOWN_ACTION` | The action is not known |
| `INVALID_CONFIG` | `apply_config` failed validation: bad hash, duplicate number or address, a second board address, another `adapter_type`, or too many compartments |
| `RUNTIME_CONFIG_NOT_APPLIED` | An open before any mapping |
| `COMPARTMENT_NOT_FOUND` | An open of an unmapped compartment |
| `HARDWARE_ERROR` | The unlock was not sent, or its outcome is unknown |
| `SHUTTING_DOWN` | The unit is closing, for example after new settings were stored |
| `UNKNOWN_ERROR` | Anything else, for example too many responses waiting |
| `UPDATE_INVALID` | A malformed update command, a URL that is not HTTPS, or one over 256 characters |
| `UPDATE_BUSY` | A door is being watched, or an update is running |
| `UPDATE_NOT_READY` | Unhealthy journal, bus not connected, image on trial, or running version not comparable |
| `UPDATE_SAME_VERSION` | The unit already runs this version |
| `UPDATE_DOWNGRADE` | A lower version, without `allow_downgrade` |
| `UPDATE_VERSION_REJECTED` | The unit rolled this version back |
| `UPDATE_DOWNLOAD_FAILED` | Reported as an event, after an accepted update fails to download |
| `UPDATE_VERIFY_FAILED` | Reported as an event, after an accepted update fails verification |

The `UPDATE_*` codes are planned with over-the-air updates ([#336](https://github.com/Open-Locker/Open-Locker/pull/336)) and not
implemented yet.

## Limits

- **No production unit may ship.** Flash and NVS encryption are off, and the `nvs_keys` partition is reserved
  for when they are turned on. Credentials and the setup secrets are unencrypted on the chip.
- **The journal remembers the newest 256 delivered commands**, `apply_config` included. An older copy of a
  command that arrives later runs again.
- **At most 32 responses can wait for the broker.** New commands are then refused with `UNKNOWN_ERROR`, and
  delivering the stored responses clears the limit.
- **A burst of more than about 30 commands in 3 s can be refused.** After a compaction, the spare journal region
  is erased one sector per idle 100 ms tick.
- **Boot may erase the spare journal region** before it takes commands, if the region holds records: up to
  32 sector erases, about 1.5 s and at worst 16 s.
- **An unhealthy journal stops all actuation.**
- **One panel per bus.** A second board address is rejected, and so is any `adapter_type` other than
  `rs485_lock_board`. Up to 64 compartments can be mapped, but the bench panel has 8 channels.
- **The broker certificate must chain to a public CA** in the built-in bundle. Certificate and host name checks
  cannot be turned off. A private CA has to be added to the build.
- **A command with no free slot is dropped and logged** when all 32 slots are in use, and the backend times out.
- **Not established:** the lock model, and the model and ratings of the power converter.

The command, response and compartment counts above are firmware settings, not chip limits, so a chip with more
flash or RAM would not raise them by itself. Commands also run one at a time, so a longer queue would only make the
last command later.

## Flash layout

The layout is fixed ([ADR-0078](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0078-esp32-flash-layout.md))
and cannot change over the air. It uses the first 4 MB of flash. A larger chip should run it with the rest unused, but
this has not been tried on a board. The partition table sits at `0xC000`, and the bootloader starts at `0x1000`.

| Partition | Offset | Size | Holds |
| --- | --- | --- | --- |
| `nvs` | `0xD000` | 24 KiB | Settings, identity, mapping and setup secrets |
| `otadata` | `0x13000` | 8 KiB | Which app slot boots. Empty means `ota_0` |
| `phy_init` | `0x15000` | 4 KiB | Radio calibration |
| `nvs_keys` | `0x16000` | 4 KiB | Reserved for NVS encryption, unused |
| `ota_0` | `0x20000` | 1856 KiB | Firmware. A USB flash writes it here |
| `ota_1` | `0x1F0000` | 1856 KiB | Second slot, written by an update |
| `journal` | `0x3C0000` | 256 KiB | The command journal, raw, subtype `0x40` |

Explore the [interactive flash and memory map](/dokumentation/esp32/memory/) for a production-config image footprint,
and the difference between reserved capacity and measured usage.

## For developers

- [Develop the ESP32 firmware](/dokumentation/esp32-development/): builds, gates, host tests and how to add a test.
- [Hardware acceptance](/dokumentation/esp32-hardware-acceptance/): the bench checks and their record.
- [ESP32 firmware internals](/dokumentation/esp32-internals/): layers, ports, the task model, journal format, setup network, update order, rollout status.
- Decisions: [ADR-0069](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0069-esp32-locker-firmware.md) (firmware),
  [ADR-0070](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0070-esp32-command-journal-format.md) (journal),
  [ADR-0071](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0071-rs485-lock-panel-replies-with-active-feedback.md) (panel),
  [ADR-0074](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0074-esp32-softap-provisioning.md) (setup network),
  [ADR-0075](https://github.com/Open-Locker/Open-Locker/blob/main/docs/adr/0075-esp32-ota-firmware-updates.md) (updates).
