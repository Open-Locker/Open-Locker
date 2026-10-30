# ADR-0069: ESP32 locker firmware

## Status

Proposed

## Date

2026-10-21 (first drafts 2026-10-15 to 2026-10-21)

## Context

Issue #56 asks for a locker client that runs on an ESP32 instead of a Raspberry
Pi. It must speak the existing MQTT contract (`docs/asyncapi/`), so the backend
cannot tell the two clients apart, and keep every safety rule of the Pi client:
no unlock is sent twice for one request, a power cut never causes a second
unlock, and the one-time provisioning token is sent at most once.

An ESP32 has no operating system in the PC sense, a few hundred kilobytes of
RAM and a 4 MB flash that the board selection (ADR-0005) assumes. The bench
board has 4 MB (confirmed 2026-10-21); production boards are not chosen yet.
Some choices made now cannot be changed later without a USB reflash of every
unit: ESP-IDF cannot replace the bootloader or the partition table over the
air. No unit is deployed, so all of them are still free.

The first firmware is developed against the proprietary RS485 lock panel
(`adapter_type = rs485_lock_board`, ADR-0061), confirmed on the bench as an
SY-800R with 8 channels at board address 1, on an ESP32 D1 mini (chip revision
3.1) with an XY-485 automatic-direction converter. That is bench hardware, not
a production board selection; ADR-0005 stays a proposal until the firmware has
passed its hardware tests.

## Decision

1. **A separate firmware, not a port of the Pi client.** `locker-firmware/` is
   written in C on ESP-IDF and shares the MQTT schemas and the panel's golden
   frames with `locker-client/`, but no code. A bank is driven by a Pi or by an
   ESP32, never both.
2. **Same layering as the Pi client.** Hexagonal (ADR-0024): domain, ports,
   application, one ESP-IDF component per adapter, and `main/` as composition
   root. The core includes no SDK, RTOS or JSON header and builds on a host; a
   script in the verify gate enforces it.
3. **One owner task.** Every use case runs on one task that takes events from a
   bounded queue, so commands and `apply_config` never interleave. Overflow
   never actuates: a dropped command is never claimed and the backend times
   out.
4. **One RS485 lock panel per bank.** `rs485_lock_board` is the only supported
   `adapter_type`, with one board address per bus.
5. **Command journal in a raw flash partition**, written before the panel is
   touched. Its format and retention are ADR-0070.
6. **Final flash layout from the first firmware.** 4 MB flash, two equal OTA
   app slots and no `factory` slot, a reserved `nvs_keys` partition, the
   journal at the end of flash, and the partition table moved to `0xC000` to
   give the bootloader room for secure boot. OTA itself is #59.
7. **ESP-IDF is pinned to exactly v6.1**, with `dependencies.lock` committed.
   Firmware, CI, Docker image, README and host tools that speak the firmware's
   protocols use the same release. A patch release is adopted through a pull
   request and a bench run, never by floating a tag.
8. **USB commissioning for the bench.** Wi-Fi, broker and token are set over
   the USB serial console with `scripts/commission.py --usb`. The SoftAP portal
   (#57, ADR-0074) is a second driving adapter over the same use case and the
   only commissioning path in production builds, which have no `configure`
   command (`CONFIG_LC_BENCH_CONSOLE` off).
9. **TLS only in release builds.** A release binary accepts only `mqtts://`,
   always verifies certificate and host name, and has no path to a plaintext
   connection. Plain `mqtt://` exists only behind the build-time option
   `CONFIG_LC_ALLOW_PLAINTEXT_MQTT` (default off) for bench work against the
   local Mosquitto. Skipping TLS verification is a build error in every
   configuration.
10. **Plaintext credentials are accepted for the bench only.** Wi-Fi and MQTT
    credentials sit unencrypted in NVS until flash and NVS encryption are
    turned on. Until then the firmware is not for a cabinet in the field.
    Superseded by ADR-0074 decision 18, which keeps this for bench builds and
    makes encryption a release blocker.

How each point is implemented (partition table, Kconfig options, component
list, mapping from the Pi client) is in `locker-firmware/docs/architecture.md`.

## Rationale

- **C on ESP-IDF** is the only stack with official support for everything the
  firmware needs: Wi-Fi, MQTT over TLS, NVS, flash encryption and OTA. The
  official Rust SDK does not yet cover them; the wrappers that do are
  community-maintained.
- **Same layering** keeps the two clients comparable test for test and lets
  the safety-critical core run under sanitizers on a host.
- **One owner task** is the simplest concurrency rule that makes "a lock is
  never released twice" hold by construction.
- **Layout now** means the first firmware's hardware tests run on the layout
  that ships. Two OTA slots without a factory slot use the app space fully, and
  ESP-IDF's anti-rollback requires no factory slot anyway.
- **A pinned toolchain** makes the build that passes CI the build tested on the
  bench, and keeps host tools such as `esp_prov` in step with the firmware.
- **USB first** gets the firmware onto a bench without a phone flow; the
  commissioning use case is the same either way.
- **A build-time TLS switch** cannot be flipped by anyone at the USB console or
  the coming setup portal; a runtime flag could.

## Alternatives Considered

### Alternative A: Run the Pi client's TypeScript on the ESP32 or share a core

- Pros: one implementation of the contract.
- Cons: no JavaScript runtime with TLS MQTT and flash access fits the ESP32;
  a shared C core would force a native module onto the Pi client.
- Why not chosen: shared schemas, golden frames and ported test names control
  drift at much lower cost.

### Alternative B: Keep a `factory` slot and add smaller OTA slots

- Pros: a "last resort" image.
- Cons: slots of about 1.1 MB, 9 % above today's image; the bootloader keeps
  2.7 KB of headroom; rollback never uses the factory image.
- Why not chosen: no unit is deployed, so keeping offsets buys nothing.

### Alternative C: Plain MQTT as a runtime flag

- Pros: one binary for every environment.
- Cons: anyone with access to the USB console or the setup portal could
  downgrade a deployed unit to clear text.
- Why not chosen: release security would depend on a stored value.

### Alternative D: TLS everywhere with a development CA, or `mqtts://` without verification

- Pros: development exercises the TLS path.
- Cons: a development CA needs certificates for every developer's broker; an
  unverified TLS peer looks secure and is easy to leak into a release.
- Why not chosen: too much setup for bench work, or worse than an honest plain
  connection. A development CA stays the right choice for integration tests that
  must cover TLS.

### Alternative E: ESP-IDF 5.5.x or 6.0.x

- Pros: older, more settled.
- Cons: 5.5 gets security fixes only; 6.0 has a shorter service window.
- Why not chosen: either would need a second toolchain move soon.

### Alternative F: SoftAP commissioning and encryption in the first firmware

- Pros: field-ready from the start.
- Cons: a much larger first change, with flash encryption hard to undo on a
  bench board.
- Why not chosen: the bench needs neither. SoftAP comes in #57 (ADR-0074);
  encryption is a release blocker there (ADR-0074 decision 18).

## Consequences

### Positive

- The backend, admin panel and mobile app are unchanged.
- OTA, flash encryption and NVS encryption later need no new flash layout.
- Release builds cannot connect without verified TLS, checked in CI.

### Negative

- Two implementations of one contract to keep in step.
- Two build configurations; switching needs a deleted `sdkconfig`.
- The first build needs network access to the component registry.

### Risks

- If a board has less than 4 MB of flash the layout does not fit; check every
  board with `esptool.py flash_id`.
- A development build flashed onto a deployed unit allows plain MQTT. The log
  warns on every connect. Signed images (#59) stop such a build arriving over
  the air; only secure boot, which is not planned yet, stops it over USB.
- Credentials in plaintext NVS can be read from a stolen unit until flash and
  NVS encryption are turned on (ADR-0074 decision 18).
- ESP-IDF 6.1 supports no static-RSA or finite-field DHE key exchange; one
  `mqtts://` handshake against the real broker is still to be done on the bench.

## Rollout / Migration

No unit is deployed, so nothing migrates; bench boards are reflashed over USB.
Once units are deployed, changing the flash layout means a USB reflash of every
unit and a new ADR. To hand a bank back to the Pi client, reset
provisioning in the backend and enroll the Pi with a new token.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: ADR-0074 supersedes point 10 (decision 18).

## References

- Related PRs: Open-Locker/Open-Locker#321, Open-Locker/Open-Locker#331
- Related issues: #56, #57, #58, #59
- Related docs: `locker-firmware/docs/architecture.md`,
  `locker-firmware/README.md`, ADR-0005, ADR-0024, ADR-0053 (public MQTT TLS at
  Traefik), ADR-0061, ADR-0070
- ESP-IDF v6.1: [Partition Tables](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-guides/partition-tables.html),
  [Over The Air Updates](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/system/ota.html),
  [NVS Encryption](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/storage/nvs_encryption.html)
