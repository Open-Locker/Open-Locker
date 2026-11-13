# ADR-0072: ESP32 locker firmware in C on a pinned ESP-IDF

## Status

Accepted

## Date

2026-10-21

## Context

Issue #56 asks for a locker client that runs on an ESP32 instead of a Raspberry
Pi. It must speak the existing MQTT contract (`docs/asyncapi/`), so the backend
cannot tell the two clients apart, and keep every safety rule of the Pi client:
no unlock is sent twice for one request, a power cut never causes a second
unlock, and the one-time provisioning token is sent at most once.

An ESP32 has no operating system in the PC sense and a few hundred kilobytes of
RAM. The firmware is developed against the proprietary RS485 lock panel
(`adapter_type = rs485_lock_board`, ADR-0061), confirmed on the bench as an
SY-800R with 8 channels at board address 1, on an ESP32 D1 mini (chip revision
3.1) with an XY-485 automatic-direction converter. That is bench hardware. No
production board has been chosen.

## Decision

We write the ESP32 client as a separate firmware, `locker-firmware/`, in C on
ESP-IDF, pinned to exactly v6.1.

- **Separate.** It shares the MQTT schemas and the panel's golden frames with
  `locker-client/`, but no code. A bank is driven by a Pi or by an ESP32, never
  both.
- **Pinned.** `dependencies.lock` is committed. Firmware, CI, Docker image,
  README and the host tools that speak the firmware's protocols use the same
  release. A patch release is adopted through a pull request and a bench run,
  never by floating a tag.
- **Bootloader.** ESP-IDF cannot replace the bootloader over the air. It boots
  apps built with newer ESP-IDF versions but not older ones, so later ESP-IDF
  upgrades happen in the app only.
- **Same layering as the Pi client** (ADR-0024). The core includes no SDK, RTOS
  or JSON header and builds on a host; a script in the verify gate enforces it.
- **Scope.** `rs485_lock_board` is the only supported `adapter_type`, with one
  panel per bank. Other adapter types and a second board address are rejected.

The components, ports and the mapping from the Pi client are in
`website/src/content/docs/dokumentation/esp32-internals.md`.

## Rationale

- C on ESP-IDF is the only stack with official support for everything the
  firmware needs: Wi-Fi, MQTT over TLS, NVS, flash encryption and OTA. The
  official Rust SDK does not yet cover them; the wrappers that do are
  community-maintained.
- The same layering keeps the two clients comparable test for test and lets the
  safety-critical core run under sanitizers on a host.
- A pinned toolchain makes the build that passes CI the build tested on the
  bench, and keeps host tools such as `esp_prov` in step with the firmware.

## Alternatives Considered

### Alternative A: Run the Pi client's TypeScript on the ESP32 or share a core

- Pros: one implementation of the contract.
- Cons: no JavaScript runtime with TLS MQTT and flash access fits the ESP32; a
  shared C core would force a native module onto the Pi client.
- Why not chosen: shared schemas, golden frames and ported test names control
  drift at much lower cost.

### Alternative B: ESP-IDF 5.5.x or 6.0.x

- Pros: older, more settled.
- Cons: 5.5 gets security fixes only; 6.0 has a shorter service window.
- Why not chosen: either would need a second toolchain move soon.

## Consequences

### Positive

- The backend, admin panel and mobile app are unchanged.

### Negative

- Two implementations of one contract to keep in step.
- The first build needs network access to the component registry.

### Risks

- ESP-IDF 6.1 supports no static-RSA or finite-field DHE key exchange, so the
  real broker has to offer a suite it accepts (see the Confirmation of
  ADR-0079).

## Rollout / Migration

No unit is deployed, so nothing migrates. To hand a bank back to the Pi client,
reset provisioning in the backend and enroll the Pi with a new token.

## Confirmation

- Layering: the layer include check in `scripts/verify.py`.
- Pin: the `locker-firmware-ci` workflow builds with v6.1.
- Scope: S2-H16 and S2-H17.
- Same behaviour as the Pi client on a real panel: HW-A1, HW-A6, HW-C4 and
  HW-C5.
- Handing a bank back to the Pi client: HW-G2.
- Bootloader: HW-H10 (it still boots an app built with the next patch release).

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#321
- Related issues: #56, #58
- Related docs:
  `website/src/content/docs/dokumentation/esp32-internals.md`,
  ADR-0024, ADR-0061
- Flash layout: [ADR-0078](0078-esp32-flash-layout.md). Concurrency:
  [ADR-0080](0080-esp32-owner-task.md). MQTT security:
  [ADR-0079](0079-esp32-mqtt-tls-only-in-release-builds.md).
