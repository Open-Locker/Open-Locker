# Locker firmware (ESP32, RS485 lock panel)

Firmware for an ESP32 that does what the Raspberry Pi `locker-client/` does for
one RS485 lock panel: it takes `open_compartment` and `apply_config` commands
from the broker, releases the lock, watches the door, and reports state. It
speaks the same MQTT contract (`docs/asyncapi/`), so the backend does not know
which of the two it is talking to.

This is bench firmware. Read [Limits](#limits) before you put a lock on it.

- Plan and slices: [`docs/plans/esp32-rs485-hexagonal-client.md`](../docs/plans/esp32-rs485-hexagonal-client.md)
- Journal format and retention: [ADR-0068](../docs/adr/0068-esp32-command-journal-record-format-and-retention.md)
- Hardware: [ADR-0005](../docs/adr/0005-esp-build-controller-board-selection.md)

## Hardware

| Part | Notes |
| --- | --- |
| ESP32 D1 mini | PlatformIO board `wemos_d1_mini32`, USB data cable |
| XY-485 converter | Automatic direction: no DE/RE pin to drive. Powered from the ESP32's 3V3 |
| RS485 lock panel | Own supply; check the seller's documentation for the voltage |
| A lock on channel 1 | The panel times the release pulse; the firmware only sends commands |

Wiring, with the build defaults from `components/lc_serial_uart/Kconfig`:

| ESP32 | XY-485 |
| --- | --- |
| GPIO 17 (UART 2 TX) | RXD |
| GPIO 16 (UART 2 RX) | TXD |
| 3V3 | VCC |
| GND | GND |

A and B of the converter go to A and B of the panel. UART 0 is the USB console
and cannot be used for the bus. The serial parameters default to 9600 baud, 8N1
and the timing to `components/lc_rs485_lock_board/Kconfig`; **none of these has
been confirmed against the panel yet** (plan, section 1.5). Change them with
`pio run -t menuconfig`.

## Build and test

```bash
node scripts/verify.mjs               # static and host gates, and the target build
node scripts/verify.mjs --skip-target # without PlatformIO
pio run -d .                          # target build only
pio run -d . -t upload                # flash the board
pio device monitor                    # console and log, 115200 baud
```

`verify.mjs` is the one command CI runs. It needs Node 20 or later, CMake, and a
C compiler (clang or gcc) for the host gates, and PlatformIO for the target
build. It checks the plan IDs of the tests, the layer rules (see below), static
analysis of the pure components, the host suites with AddressSanitizer and
UndefinedBehaviorSanitizer, every encoded MQTT message against its AsyncAPI
schema, the Node tests of the tools, and line coverage of at least 90 % for the
pure components.

## How it is laid out

`components/` has one component per layer and one per adapter. A component may
only depend on the ones to its left:

```text
lc_domain  <-  lc_ports  <-  lc_application  <-  adapters  <-  main
```

| Component | What it is |
| --- | --- |
| `lc_domain` | Pure C: compartments, mapping and its hash, door detection |
| `lc_ports` | Header-only interfaces: bus, journal, outbound messages, stores, clock |
| `lc_application` | Use cases: commands, `apply_config`, state publishing, enrollment, commissioning |
| `lc_mqtt_codec` | Topics and JSON, both directions (pure, with cJSON) |
| `lc_journal` | The append-only command journal (pure, over a flash I/O struct) |
| `lc_rs485_lock_board`, `lc_serial_uart` | The bus adapter and its UART |
| `lc_usb_console` | Line protocol of the USB console |
| `lc_storage_nvs`, `lc_net_wifi`, `lc_mqtt_espidf` | Settings and journal flash, Wi-Fi and SNTP, MQTT transport |
| `main/` | Composition root, owner task, MQTT session, bench mode |

All use cases run on one task, the owner task, one event at a time. Everything
else posts events to its queue. That is what keeps an `apply_config` from
overlapping an unlock.

The MQTT transport only moves topics and bytes. `main/mqtt_session.c` joins it
to the codec, so no adapter depends on another.

## Commissioning over USB

A new board has no network settings. Give it some with the tool:

```bash
npm install                                  # once, for the serial port
node scripts/commission.mjs --port COM3      # or /dev/ttyUSB0
```

It asks for the Wi-Fi network name and password, the broker URL (`mqtts://`
only), and, for a device that is not enrolled yet, the bootstrap user and
password and the one-time provisioning token that the backend shows (#58). The
secrets are read without echo, go to the device and nowhere else, and are never
printed or stored. The device saves them, restarts, joins the network and
enrolls: it presents the token once, receives its own MQTT identity, and
restarts again to connect with it.

Things to know:

- **The token is used once.** The device stores "enrollment pending" before it
  sends the token. If the reply is lost or never comes, the device does **not**
  try again and refuses new settings until it is reset. The tool never resets
  by itself.
- **A network change on an enrolled device** only needs the network name,
  password and broker. Identity, mapping and journal are kept.
- If the connection to the tool is lost after the settings were sent, nothing
  is resent. Run `status` in the console, then try again.

### Console commands

Type these into the serial monitor, one line each (up to 1 KiB). Every reply is
one line starting with `ok` or `error`; a reply never repeats a value you sent.

| Command | What it does |
| --- | --- |
| `status` | Bus state, and whether the device is enrolled, an enrollment is pending, and settings are stored |
| `diagnostics` | Bus state and the number of unlock requests that reached the wire since boot |
| `self-test` | Checks the frame encoder. Sends nothing to the panel |
| `query <board>` | Reads the door state of addresses 0 to 31 of a panel (`c` closed, `o` open, `?` unknown) |
| `unlock <board> <address>` | **Releases the lock** at that address, once. Bench use: it bypasses the journal, so it is refused once the device is enrolled or has network settings |
| `command <json>` | Feeds one MQTT command payload to the application, as if the broker had sent it |
| `configure <json>` | What the commissioning tool sends |
| `reset-network` | Forgets network settings, identity and mapping, then restarts. The journal is kept |

### Bench mode

A device with no network settings has no broker, so the console stands in for
it. With `command <json>` you can send `apply_config` and `open_compartment`
exactly as the backend would, and the device prints what it would publish as
`out <kind> <json>`. This is how the bus, the mapping and the journal are
tested without a radio (hardware checks B1 to B6). As soon as network settings
are stored, commands come from the broker only.

## Returning a bank to the Pi client

1. Disconnect the ESP32 from the panel (cut its power first, then the RS485
   pair).
2. In the backend, reset the provisioning of the locker bank and create a new
   token, so the Pi client enrolls with fresh credentials. The ESP32's identity
   stops working at that moment.
3. Start the Pi client with the new token (`locker-client/`). The mapping is
   sent again by the backend.

To use the ESP32 again, run `reset-network` first, and enroll it with a new
token.

## Limits

- **The journal holds 64 commands, ever.** There is no pruning and no erase
  (ADR-0068). `apply_config` counts. When it is full, new commands are refused
  with `UNKNOWN_ERROR` and earlier responses are still replayed. To clear it,
  erase the `journal` partition, for example
  `esptool.py --port COM3 erase_region 0x190000 0x40000`, and commission again.
  The 72-hour endurance check (HW-G1) sends more commands than that and needs the
  journal erased between runs until a retention scheme is decided.
- **An unhealthy journal stops all actuation.** A corrupt record, a journal
  partition that is missing, or a second power loss during repair does this.
  The console shows it in the boot log. Erase the partition to recover.
- **One panel per bus.** A second board address is rejected, and so is any
  `adapter_type` other than `rs485_lock_board`.
- **Credentials are stored unencrypted** in flash until flash encryption is
  decided. Do not leave a unit unattended in a place anyone can reach.
- **The broker certificate must chain to a public CA** in the built-in bundle
  (for example the Let's Encrypt certificate of ADR-0053). Certificate and host
  name verification cannot be turned off. A broker with a private CA needs the
  CA added to the build.
- **No OTA, no SoftAP portal.** OTA is #59. The SoftAP portal is the next pull
  request.
- **Unconfirmed hardware facts:** panel model, supply voltage, channel count,
  lock model, board flash size (the partition table assumes 4 MB), UART pins,
  serial parameters, DIP address and feedback polarity. The host tests establish
  none of them; the hardware checks in plan section 5.1 do.

## Layout of flash

`partitions.csv`: `nvs` for settings, identity and mapping; `factory` for the
firmware; `journal` (256 KiB, raw, subtype `0x40`) for the command journal.
