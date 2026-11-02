# ADR-0085: ESP32 trial boot and rollback

## Status

Accepted

## Date

2026-10-22

## Context

A new image that cannot reach the network or the panel must not leave a unit
dead at a cabinet, and must not leave it unable to read its own command journal
or identity.

Facts checked against ESP-IDF v6.1: with
`CONFIG_BOOTLOADER_APP_ROLLBACK_ENABLE`, a new app boots as
`ESP_OTA_IMG_PENDING_VERIFY`, and the app calls
`esp_ota_mark_app_valid_cancel_rollback()` or
`esp_ota_mark_app_invalid_rollback_and_reboot()`. A **reset** while pending
rolls back; nothing rolls back by time alone. With an erased `otadata` and no
factory slot the bootloader boots `ota_0`.

## Decision

A new image runs on trial and confirms itself, or the unit goes back.

- **Confirmation.** The image marks itself valid only after Wi-Fi, the broker
  (the command subscription is confirmed) and one successful bus query have all
  happened. Each is remembered once it has happened; they need not hold at the
  same moment.
- **Trial window.** An app-side timer of **180 s** (`LC_TRIAL_WINDOW_MS`) from
  the application's start. If the three have not happened by then, or the valid
  mark cannot be stored, the app calls
  `esp_ota_mark_app_invalid_rollback_and_reboot()`. That returns only on
  failure; if it returns, as after a USB flash with empty `otadata`, the app
  calls `esp_restart()`.
- **Opens are allowed during the trial.** The journal keeps them exactly once
  across a rollback reboot.
- **Formats across rollback.** If image N+1 writes a new journal or NVS format
  and then rolls back, image N finds an unknown version and stops all actuation
  until the journal is erased over USB. So a release that changes the journal or
  NVS format must ship only after a release that can already **read** the new
  format, and the writer must not switch formats until it has marked itself
  valid.

## Rationale

The bootloader rolls back only on a reset, so an app-side timer is the only way
to get a time limit. The window keeps running without the network, because a
broken image may be exactly the one that cannot reach it. Today exactly one
journal format and one NVS format exist, so the format rule cannot fail yet and
has no host test; it becomes one with the first format change.

## Alternatives Considered

None were recorded when this was decided.

## Consequences

### Positive

- A bad image, a lost link or a missing broker returns the unit to the old
  version unaided.

### Negative

- The backend learns of a rollback only from a heartbeat
  ([ADR-0076](0076-update-firmware-mqtt-command-and-heartbeat-fields.md)).

### Risks

- A good image rolls back if Wi-Fi or the broker is down during the whole trial
  window after the reboot. The old image then refuses that version
  (`UPDATE_VERSION_REJECTED`), and recovery is a new release with the next patch
  version.
- **Open: trial window.** 180 s is to be tuned on the bench.
- **Open: NVS format compatibility across future ESP-IDF versions** was not
  researched.

## Rollout / Migration

None. The first format change adds the host test for the format rule.

## Confirmation

- S7-H08 (marked valid only after all three), S7-H09 (rolled back after the
  window), S7-H15 (a rollback that returns falls back to a restart).
- HW-H3: an open during the trial window of a rollback is handled once.
- HW-H6: a valid image that cannot reach the broker goes back unaided.
- HW-H8: identity, mapping and every journal record are intact after a rollback.
- HW-H11: after rolling back from an image that handled commands, the previous
  image reads the journal and keeps actuating.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#336
- Related issues: #59
- Related docs: [ADR-0075](0075-esp32-ota-firmware-updates.md),
  [ADR-0070](0070-esp32-command-journal-format.md)
- [ESP-IDF v6.1
  OTA](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/system/ota.html)
