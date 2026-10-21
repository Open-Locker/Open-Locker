# ADR-0075: ESP32 firmware pins ESP-IDF v6.1

## Status

Proposed

## Date

2026-10-21

## Context

The firmware needs one recorded toolchain, so that a build that passes CI is the
build that is tested on the bench. Host tools that speak the firmware's protocols,
such as the laptop's `esp_prov` for SoftAP commissioning (#331), must come from the
same release as the firmware.

## Decision

1. The firmware, CI, the Docker image and the README use exactly ESP-IDF `v6.1`.
   Host tools that must match the firmware use the same release.
2. The component manager is on and `locker-firmware/dependencies.lock` is
   committed. Components outside ESP-IDF (`espressif/mqtt`, `espressif/cjson`, and
   `espressif/network_provisioning` once #331 lands) come from it.
3. A patch release (6.1.1 and later) is adopted through a pull request and a
   bench run, not by floating the image tag.
4. The IDF host tests mock `espressif/mqtt` from the headers of the firmware's
   resolved copy, so the firmware build runs before them.

## Alternatives Considered

### Alternative A: ESP-IDF 5.5.x

- Pros: older, more settled.
- Cons: security fixes only.
- Why not chosen: it would need a second move later.

### Alternative B: ESP-IDF 6.0.3

- Pros: one release behind 6.1.
- Cons: a shorter service window.
- Why not chosen: 6.1 is the current release.

## Consequences

### Positive

- One pinned toolchain; firmware and host tools cannot drift apart.

### Negative

- The first build needs network access (component registry).
- The host tests depend on a firmware build having resolved `managed_components/`.

### Risks

- ADR-0070's open point (whether esp-mqtt sends the PUBACK after the event handler
  returns) is checked against `espressif/mqtt` 1.1.0 on the bench.
- ESP-IDF 6.1 supports no TLS 1.2 key exchange without forward secrecy (static RSA)
  and no finite-field DHE. One `mqtts://` handshake against the real broker is
  still to be done on the bench.

Measured on the default configuration (ESP32): the app is 0xfe540 bytes, with
0xd1ac0 bytes (45%) of the 0x1d0000 app slot free.

## Rollout / Migration

Bench boards are flashed in full (`idf.py flash -a`), which also writes the
bootloader. The hardware checks of `locker-firmware/docs/testing.md` run on the
6.1 build.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none
