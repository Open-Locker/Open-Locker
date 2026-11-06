# ADR-0075: ESP32 OTA updates run beside the owner task and start only when idle

## Status

Accepted

## Date

2026-10-22

## Context

Issue #59 asks for over-the-air updates of the ESP32 locker firmware. The firmware
actuates locks. An update must never unlock anything, never start while a command is
in flight, and never replace the working image with an unverified one.

Related decisions: the MQTT contract is
[ADR-0076](0076-update-firmware-mqtt-command-and-heartbeat-fields.md), image signing
[ADR-0084](0084-esp32-firmware-image-signing.md), trial boot and rollback
[ADR-0085](0085-esp32-trial-boot-and-rollback.md), and the backend
[ADR-0077](0077-backend-firmware-rollout-tracking.md) and
[ADR-0086](0086-backend-firmware-images-and-download-link.md).

## Decision

We will update through `esp_https_ota` on a task of its own, under these rules:

- **The download runs in its own task**, never the owner task, and reports how it
  ended as an event. HTTPS only, with the same CA bundle as MQTT and no way to turn
  certificate verification off.
- **An update is refused, not queued, unless the unit is idle:** no unlock whose door
  is still being watched (ADR-0040), journal healthy, bus connected, no update already
  running, and the running image not on trial.
- **The slot is selected only after verification.** The new image becomes the next
  boot slot only when `esp_https_ota_finish` returns success. Any failure leaves the
  running slot selected.
- **The restart also waits** until no door is being watched.
- **A failed download restarts from byte 0** on the next command. There is no resume.
- **No update code path calls the bus unlock.**

The version policy, the header check, logging and the order of the steps are in
`website/src/content/docs/dokumentation/esp32-internals.md`.

## Rationale

The owner task must keep serving opens during a download, so the download cannot run
on it. A refused command is not lost: the backend may send it again. Restarting from
byte 0 avoids update state in NVS and a second path to test, since images are under
1.8 MB.

## Alternatives Considered

### Alternative A: HTTP download with a hash in the command

- Pros: no TLS memory cost.
- Cons: no server identity; hash and URL arrive on the same channel.
- Why not chosen: HTTPS with the CA bundle is already linked for MQTT.

### Alternative B: Download resume

- Pros: saves bandwidth on bad links.
- Cons: persistent state in NVS and a second path.
- Why not chosen: images are small enough to fetch again.

## Consequences

### Positive

- Updates need no USB visit once the first OTA-capable image is flashed.

### Negative

- Units flashed before OTA need one USB flash to get the first OTA-capable image.
- A restart after the command was accepted but before the download ends leaves nothing
  on the device to report. The half-written slot is never selected, and the backend's
  timeout marks the update failed.

### Risks

- A power cut during the write leaves the old slot untouched. A power cut between
  `finish` and the restart boots the new image on trial, which ADR-0085 covers.
- **Open: manifest polling.** Pull on command only (the current design) or also
  periodically.
- **Open: Pi `locker-client`.** Whether it later implements `update_firmware` (#70).

## Rollout / Migration

The first image of any bench or pilot unit is flashed over USB, signed.

## Confirmation

- Idle rule: S7-H02, S7-H03.
- Transport: the OTA settings gate in `scripts/verify.py` (`scripts/test_verify.py`).
- Slot selection: S7-H06, S7-H07, and `tests/idf_host/main/test_ota.c`.
- No unlock on any path: S7-H10; HW-H8.
- On a real unit: HW-H2, HW-H3, HW-H5, HW-H7 and HW-H9.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#336
- Related issues: #59, #70
- Related docs: `website/src/content/docs/dokumentation/esp32-internals.md`
- ESP-IDF v6.1:
  [OTA](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/system/ota.html),
  [esp_https_ota](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/system/esp_https_ota.html)
