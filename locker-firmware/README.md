# Locker firmware (ESP32, RS485 lock panel)

Firmware for an ESP32 that does what the Raspberry Pi `locker-client/` does for
one RS485 lock panel: it takes `open_compartment` and `apply_config` commands
from the broker, releases the lock, watches the door, and reports state. It
speaks the same MQTT contract (`docs/asyncapi/`), so the backend does not know
which of the two it is talking to.

This is bench firmware. Read [Limits](#limits) before you put a lock on it.

- Architecture: [`docs/architecture.md`](docs/architecture.md)
- Tests and hardware acceptance: [`docs/testing.md`](docs/testing.md)
- Firmware decisions: [ADR-0069](../docs/adr/0069-esp32-locker-firmware.md)
- Journal format and retention: [ADR-0070](../docs/adr/0070-esp32-command-journal-record-format-and-retention.md)
- Hardware: [ADR-0005](../docs/adr/0005-esp-build-controller-board-selection.md)

## Hardware

| Part | Notes |
| --- | --- |
| ESP32 D1 mini | ESP-IDF target `esp32`, USB data cable |
| XY-485 converter | Automatic direction: no DE/RE pin to drive. Powered from the ESP32's 3V3 |
| RS485 lock panel | SY-800R, 8 channels, board address 1, with door feedback (ADR-0071). Own supply; check the seller's documentation for the voltage |
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
and the timing to `components/lc_rs485_lock_board/Kconfig`. The bench panel
answers at 9600 8N1, in about 265 ms against the 1500 ms reply deadline
(ADR-0071); other panels may differ ([Risks](docs/architecture.md#risks)). Change them with
`idf.py menuconfig`.

## Build and test

```bash
python scripts/verify.py               # all gates, the IDF host tests and the target build
python scripts/verify.py --skip-target # without ESP-IDF
idf.py build                          # target build only
idf.py -p COM3 flash                  # flash the board
idf.py -p COM3 monitor                # console and log, 115200 baud
```

Run the `idf.py` commands from an ESP-IDF 6.1 shell (see Espressif's
[getting started guide](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/get-started/)),
or without installing it, in Docker:
`docker run --rm -v "$PWD:/project" -w /project espressif/idf:v6.1 idf.py build`.

`verify.py` is the one command for the gates. CI runs it with `--skip-target`
and runs the IDF host tests and the target build in separate steps with the
ESP-IDF container. It needs Python 3 with `pip install -r requirements-dev.txt`
(the schema validator), CMake, and a C compiler (clang or gcc) for the host
gates, and an ESP-IDF shell for the IDF host tests and the target build. Flashing and commissioning a board need only ESP-IDF and
`pip install -r requirements.txt`. On Windows, set `CMAKE_GENERATOR` and `CC`
(for example `MinGW Makefiles` and `gcc`) if CMake finds no compiler. The gate
checks the test IDs against [the catalogue](docs/testing.md), the layer rules (see below), static
analysis of the pure components, the host suites with AddressSanitizer and
UndefinedBehaviorSanitizer, every encoded MQTT message against its AsyncAPI
schema, the Python tests of the tools, and line coverage of at least 95 % for the
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
| `lc_journal` | The command journal: two flash regions with compaction (pure, over a flash I/O struct) |
| `lc_rs485_lock_board`, `lc_serial_uart` | The bus adapter and its UART |
| `lc_usb_console` | Line protocol of the USB console |
| `lc_storage_nvs`, `lc_net_wifi`, `lc_mqtt_espidf` | Settings and journal flash, Wi-Fi and SNTP, MQTT transport |
| `lc_ota_espidf` | Over-the-air updates on `esp_https_ota` and `esp_ota_ops`; the download runs on its own task |
| `main/` | Composition root, owner task, MQTT session, bench mode |

All use cases run on one task, the owner task, one event at a time. Everything
else posts events to its queue. That is what keeps an `apply_config` from
overlapping an unlock.

The MQTT transport only moves topics and bytes. `main/mqtt_session.c` joins it
to the codec, so no adapter depends on another.

## Commissioning over the setup network

Not yet tried on a bench unit (ADR-0074). A unit with no network settings opens a setup Wi-Fi
for 10 minutes after power-up (name and password on its label). From a
laptop, run `python scripts/commission.py --softap` (after a firmware build, which fetches its `esp_prov`); it says when to join that network
(see its docstring and ADR-0074). `scripts/device_secrets.py` makes the label
secrets for a new unit. Hold the unit's reset button for 10 s to wipe it.

## Commissioning over USB

The console commands below (`configure`, `unlock`, `command`, `reset-network`)
exist in bench and development builds only. A production build is made with
`idf.py -D SDKCONFIG_DEFAULTS="sdkconfig.defaults;sdkconfig.production" build`
and has none of them (`CONFIG_LC_BENCH_CONSOLE=n`); it is commissioned through
the setup network (ADR-0074). Neither build has flash or NVS encryption yet, so
no production unit may ship (ADR-0074).

A new board has no network settings. Give it some with the tool:

```bash
pip install -r requirements.txt              # once: pyserial, paho-mqtt, questionary
python scripts/commission.py --usb           # finds the board; or --usb COM3, /dev/ttyUSB0
```

It asks for the Wi-Fi network name and password, the broker URL (`mqtts://`
only; a development build also takes `mqtt://`, see below), and, for a
device that is not enrolled yet, the bootstrap user and password and the one-time provisioning token that the backend shows (#58). The
secrets are read without echo, go to the device and nowhere else, and are never
printed or stored. The device saves them, restarts, joins the network and
enrolls: it presents the token once, receives its own MQTT identity, and
restarts again to connect with it.

Things to know:

- **The token is used once.** The device stores "enrollment pending" before it
  sends the token. If the reply is lost or never comes, the device does **not**
  try again and refuses new settings until it is reset. After a restart it
  still listens for a late reply and enrolls if one arrives. The tool never resets
  by itself.
- **A network change on an enrolled device** only needs the network name,
  password and broker. Identity, mapping and journal are kept.
- If the connection to the tool is lost after the settings were sent, nothing
  is resent. Run `status` in the console, then try again.
- **Plain `mqtt://` against a local broker** needs a development build
  (ADR-0069). Delete `sdkconfig`, then
  `idf.py -D SDKCONFIG_DEFAULTS="sdkconfig.defaults;sdkconfig.dev" build`.
  A plain `idf.py build` is the release configuration and refuses `mqtt://`,
  including a URL stored earlier by a development build.

### Console commands

Type these into the serial monitor, one line each (up to 1 KiB). Every reply is
one line starting with `ok` or `error`; a reply never repeats a value you sent.
What you type is not echoed, so the screen stays blank until the reply. A line
ends with CR, LF or CRLF, so Enter works in `idf.py monitor` (which sends CR) and
in other terminals.

| Command | What it does |
| --- | --- |
| `status` | Bus state, and whether the device is enrolled, an enrollment is pending, and settings are stored |
| `diagnostics` | Bus state, the number of unlock requests that reached the wire since boot, the lowest free heap since boot and the owner task's unused stack (bytes) |
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

## Updating the firmware over the air

A unit updates itself when the backend sends `update_firmware` (ADR-0075, ADR-0076,
ADR-0077). It downloads the image over HTTPS from a short-lived link, checks the
RSA-3072 signature (Secure Boot v2 format, hardware secure boot off), boots it on
trial, and keeps it only if Wi-Fi, the broker and the bus all work within 180 s.
Otherwise it goes back to the previous image by itself. Opens keep working
throughout, and an update never unlocks anything.

**Version.** The image version is semantic versioning, without a `v` or build
metadata. A release build passes it, from the release tag, in the environment:
`LC_FIRMWARE_VERSION=0.2.0 idf.py build`. The build fails on any other form (a leading
`v`, `0.2`, `01.2.3`). A build without it is `0.0.0-dev`. The variable is read at every
configure and never cached, but CMake does not watch the environment: run
`idf.py reconfigure` after changing it. A unit refuses an update to its own version, to a
lower one (unless the command allows a downgrade) and to a version it rolled back from.

**Signing.** The build writes an unsigned image. A unit checks an update against the
public key in the image it is *running*, so every image must be signed, the first one
flashed over USB included, and all with the same key. Sign after the build:

```bash
python scripts/sign_firmware.py --generate-key ~/keys/bench.pem   # once; keep it out of the repository
python scripts/sign_firmware.py ~/keys/bench.pem build/locker-firmware.bin
idf.py secure-sign-data --keyfile ~/keys/bench.pem --version 2 build/locker-firmware.bin   # the same, in place
```

The tool refuses a key inside the repository, and `*.pem` is ignored by git. A **release key**
is made offline or in an HSM, never held as a plain CI secret, with one encrypted offline
backup. **A key cannot be replaced over the air on this chip:** a unit only accepts images
signed by the key of the image it runs, and an image carries one signature. A lost or leaked
key means flashing every unit over USB. Bench units use a bench key of their own, so they
never accept a release image and release units never accept a bench image.

**First flash and update.** Flash a *signed* image over USB, the bootloader and the
partition table with it, and never the unsigned `build/locker-firmware.bin`: a unit that runs
an unsigned image cannot verify any update. `idf.py flash` writes the unsigned image, so it is
not used for a unit that must update. From the `build` directory, after signing:

```bash
python -m esptool --chip esp32 --before default-reset --after hard-reset write-flash \
  --flash-mode dio --flash-size keep --flash-freq 40m \
  0x1000 bootloader/bootloader.bin \
  0xc000 partition_table/partition-table.bin \
  0x13000 ota_data_initial.bin \
  0x20000 locker-firmware-signed.bin
```

(`flash_args` in the build directory lists the same files and offsets for the unsigned image.)
`ota_data_initial.bin` leaves `otadata` empty, so the unit boots `ota_0`. After that, upload the
signed image of a release in the admin panel and use "Update firmware" on a locker bank. The
backend shows the version the unit reports; an update is finished only when the unit reports the
new version outside its trial.

**Recovery.**

- A bad download, a failed signature or a wrong version leaves the running image in place; the
  backend marks the update failed.
- A new image that cannot reach Wi-Fi, the broker and the bus within 180 s is rolled back by
  the unit. The previous image then refuses that version; publish a new release with the next
  patch version. A good image can be rolled back by a network outage that starts during its
  reboot; the same fix applies.
- A unit that boots neither image, or whose journal or identity is damaged, is flashed over
  USB with a signed image, as under "First flash" (never plain `idf.py flash`, or the unit can
  no longer update), and then commissioned again.
- The bootloader is never replaced by an update. It boots images built with a newer ESP-IDF but
  not an older one, so ESP-IDF upgrades happen in the app only.

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

- **The journal remembers the newest 256 delivered commands** (ADR-0070).
  `apply_config` counts. An older copy of a command arriving later is executed
  again; this closes when commands carry an age (#134).
- **At most 32 responses can wait for the broker.** While 32 are unacknowledged,
  new commands are refused with `UNKNOWN_ERROR`; stored responses are still
  replayed and delivered, which clears the limit.
- **A burst of more than about 30 commands in 3 s can be refused.** After a
  compaction the spare region is erased one sector per idle 100 ms tick, and
  until it is ready no further compaction can make room.
- **Boot may erase the spare journal region** before commands are taken: only
  when it holds records, as after a compaction, with up to 32 sector erases
  (about 1.5 s, 16 s at worst). A blank spare costs one read.
- **An unhealthy journal stops all actuation.** A corrupt record, a failed flash
  write or erase, or a journal partition that is missing does this; a power cut
  does not. The console shows it in the boot log. To recover, erase the
  `journal` partition by name, for example
  `parttool.py --port COM3 --partition-table-offset 0xC000 erase_partition --partition-name journal`,
  and commission again.
- **One panel per bus.** A second board address is rejected, and so is any
  `adapter_type` other than `rs485_lock_board`.
- **Credentials are stored unencrypted** in NVS in this release. Flash
  encryption and NVS encryption (with the reserved `nvs_keys` partition) are
  not turned on yet; no production unit may ship before they are (ADR-0074
  decision 18). Until then, do not leave a unit unattended in a place anyone
  can reach.
- **The broker certificate must chain to a public CA** in the built-in bundle
  (for example the Let's Encrypt certificate of ADR-0053). Certificate and host
  name verification cannot be turned off. A broker with a private CA needs the
  CA added to the build.
- **OTA is not tried on a bench unit, and neither is the setup network.** The
  hardware checks of group H (updates) and group F (setup network) are open
  (ADR-0075, ADR-0074). Units flashed before OTA need one USB flash of a signed
  image. The key cannot be replaced over the air (see above). Credentials stay
  unencrypted until flash encryption is on, which an encrypted-image update will
  also have to take into account (ADR-0075).
- **The setup network's `esp_prov`** comes from the firmware's own build tree (ADR-0074).
- **Unconfirmed hardware facts:** panel model, supply voltage, channel count,
  lock model, board flash size (the partition table assumes 4 MB), UART pins,
  serial parameters, DIP address and feedback polarity. The host tests establish
  none of them; the [hardware checks](docs/testing.md#hardware-acceptance) do.

## Layout of flash

`partitions.csv` is the final layout (ADR-0069); it cannot change over the air.
The partition table sits at `0xC000`, so the bootloader has room to grow.

| Partition | Offset | Size | Holds |
| --- | --- | --- | --- |
| `nvs` | `0xD000` | 24 KiB | Settings, identity and mapping |
| `otadata` | `0x13000` | 8 KiB | Which app slot boots (empty: `ota_0`) |
| `phy_init` | `0x15000` | 4 KiB | Radio calibration |
| `nvs_keys` | `0x16000` | 4 KiB | Reserved for NVS encryption; unused until it is turned on (ADR-0074 decision 18) |
| `ota_0` | `0x20000` | 1.8 MiB | The firmware; `idf.py flash` writes it here |
| `ota_1` | `0x1F0000` | 1.8 MiB | Second slot, written by an update (ADR-0075) |
| `journal` | `0x3C0000` | 256 KiB | The command journal (raw, subtype `0x40`) |
