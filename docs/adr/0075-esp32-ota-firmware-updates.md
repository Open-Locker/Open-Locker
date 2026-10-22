# ADR-0075: ESP32 OTA firmware updates

## Status

Proposed

## Date

2026-10-22

## Context

Issue #59 asks for over-the-air updates of the ESP32 locker firmware
([ADR-0069](0069-esp32-locker-firmware.md)). The partition layout already has
two 1.8 MB OTA slots and an `otadata` partition, and no unit is deployed, so the
bootloader and signing settings can still be chosen freely. They cannot be
changed over the air later: a unit flashed without them needs a USB visit.

The firmware actuates locks. An update must never unlock anything, never start
while a command is in flight, never replace the working image with an unverified
one, and never leave the unit unable to read its own command journal
([ADR-0070](0070-esp32-command-journal-record-format-and-retention.md)) or
identity.

The contract for the command, response, failure event and heartbeat fields is
[ADR-0076](0076-update-firmware-mqtt-command-and-heartbeat-fields.md); the
backend side is [ADR-0077](0077-backend-firmware-releases-and-rollout-tracking.md).

Facts checked against ESP-IDF v6.1 (the version the firmware is pinned to):

- `CONFIG_SECURE_SIGNED_APPS_NO_SECURE_BOOT` ("Require signed app images") uses
  the Secure Boot v2 signature scheme without enabling hardware secure boot. It
  does not stop the bootloader being updated physically. It depends on
  `!SECURE_BOOT`. `CONFIG_SECURE_SIGNED_ON_UPDATE_NO_SECURE_BOOT` defaults to
  `y` with it and makes the OTA APIs check the signature before an image counts
  as valid.
- `CONFIG_SECURE_SIGNED_ON_BOOT_NO_SECURE_BOOT` depends on the ECDSA scheme; with
  RSA it does not exist, so the bootloader never checks signatures at boot. That
  is the intended `n`.
- A new image is verified with "the public key that is present in the signature
  block of the currently running app", and on a classic ESP32 revision v3.0 or
  later only one signature block can be appended to an app.
- RSA-3072 signing needs `CONFIG_ESP32_REV_MIN_3` (revision v3.0 or later); the
  option exists in v6.1. The bench board is revision 3.1.
- `CONFIG_BOOTLOADER_APP_ROLLBACK_ENABLE`: a new app boots as
  `ESP_OTA_IMG_PENDING_VERIFY`; the app calls
  `esp_ota_mark_app_valid_cancel_rollback()` or
  `esp_ota_mark_app_invalid_rollback_and_reboot()`. A **reset** while pending
  rolls back; nothing rolls back by time alone.
- With an erased `otadata` and no factory slot the bootloader boots `ota_0`.
- `CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP` (default `n`) allows plain HTTP and is
  marked testing only.
- `esp_https_ota` in v6.1 can resume (`ota_resumption`) and download in parts
  (`CONFIG_ESP_HTTPS_OTA_ENABLE_PARTIAL_DOWNLOAD`). The advanced API is
  `begin`, `perform`, then `finish` or `abort`, and
  `esp_https_ota_get_img_desc()` reads the version before `perform`.

The Kconfig names were read from the v6.1 sources; the doc pages for OTA, HTTPS
OTA and Secure Boot v2 were checked for the behaviour above. Not confirmed on a
board yet: the signature verification path itself (HW-H4).

## Decision

### Image authenticity

1. Images are signed with **RSA-3072 in the Secure Boot v2 format**, checked by
   the running app on update, with hardware secure boot **off**. Settings:
   `CONFIG_ESP32_REV_MIN_3=y`, `CONFIG_SECURE_SIGNED_APPS_NO_SECURE_BOOT=y`,
   `CONFIG_SECURE_SIGNED_APPS_RSA_SCHEME=y`,
   `CONFIG_SECURE_SIGNED_ON_UPDATE_NO_SECURE_BOOT=y`. RSA rather than ECDSA
   because the ECDSA option uses the older v1 format; with RSA, turning secure
   boot on later is an eFuse step, not a new signing pipeline.
2. **Every image that can ever be updated from carries a signature block**,
   including the one flashed over USB, because the running app's block supplies
   the key. A USB-flashed bench image is signed with a **bench key**, never the
   release key (see the open bench-key risk).
3. **Signing happens outside the build** (`idf.py secure-sign-data`). The build
   produces an unsigned image; signing is a separate manual or HSM step. The
   release key is generated offline or in an HSM, never stored as a plain CI
   secret, with one encrypted offline backup and a written custody procedure in
   the README.
4. **The key cannot be rotated over the air on the classic ESP32.** Only one
   signature block is possible and the next image is verified against the
   running app's block, so a new public key shipped inside an image changes
   nothing. A leaked or lost key means a USB visit to every unit. We accept
   this for the pilot and say it here instead of designing a custom verifier.
   Once hardware secure boot is on, the chip holds one key that cannot be
   revoked either.
5. Because the bootloader does not check signatures at boot, someone with
   physical access can still flash any image. The signature protects the
   network path, not the device against its holder.

### Transport

6. **HTTPS only**, with the same CA bundle as MQTT (`crt_bundle_attach`).
   Certificate verification cannot be turned off. `verify.py` fails the build if
   `CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP` is set in any sdkconfig, and the command
   decoder rejects a non-HTTPS URL (ADR-0076).
7. The download URL carries a **short-lived token** in its query string. It
   travels only on the device's private command topic and is **never logged or
   published**: not in a response, heartbeat, event, console line or error
   message. The adapter logs the host and path only.
8. A failed download restarts from byte 0 on the next command. Resume
   (`ota_resumption`) is not used: it would need update state in NVS and a
   second path to test, and images are under 1.8 MB.
9. The download runs in **its own task**, never in the owner task, and posts
   progress and results to the owner queue as events.

### Version policy

10. `PROJECT_VER` comes from the release tag. The application refuses, before it
    starts a download and with the one response: a version equal to the running
    version, lower without `allow_downgrade`, or one this unit rolled back
    before; and a running version it cannot compare. The comparison is semantic
    versioning (`lc_domain/version.h`) and the codes are in ADR-0076. The last
    rolled-back version is kept in one NVS key that an older image ignores.
    The download task then reads the image header through
    `esp_https_ota_get_img_desc()` after `begin` and **before any byte is
    written** to the other slot; a header version different from the command's
    ends the update as a failed verification (the event of ADR-0076), because
    the command has been answered by then.
11. No eFuse anti-rollback: it is irreversible per board and adds nothing while
    secure boot is off.

### When an update may start (readiness)

12. The owner task starts an update only when it is idle: no command in flight,
    journal healthy, bus connected, no update already running, and the running
    image not on trial. Otherwise the command is refused, not queued (ADR-0076
    gives the codes); the backend may send it again. The owner task runs one
    command at a time, so "in flight" means an unlock whose door is still being
    watched (ADR-0040), the one thing a restart would lose. An update that was
    accepted counts as running until it fails or the unit restarts. Any other
    error on the update path (closing, a journal that cannot claim, a restart
    before the answer was stored) is reported as `UPDATE_NOT_READY`, because the
    error schema of this action allows only `UPDATE_*` codes.
13. The update command is journaled like any other command, so a repeat with the
    same transaction id does not download twice. If the journal cannot store the
    answer after the download was started, the application aborts the download
    (the port's `abort`): nothing is published that the journal does not hold,
    and nothing keeps running that nobody knows about.
14. The new image is selected as the next boot slot
    (`esp_ota_set_boot_partition`) **only after** `esp_https_ota_finish` returned
    success, i.e. the image is verified. Any failure aborts and leaves the
    running slot selected.
15. A restart after the command was accepted but before the download ends leaves
    nothing on the device to report: the half-written slot is never selected,
    the stored answer stays "accepted", and no failure event is sent. The
    backend's timeout marks the update failed (ADR-0077).
    The restart after a verified and selected image waits for idleness again,
    with the same meaning as in decision 12: no unlock whose door is still being
    watched. Responses not yet acknowledged do not hold it back; the journal
    sends them again after the restart. Opens arriving during the download are
    served by the owner task as usual; an open is handled exactly once, never
    twice and never lost, across the update and the trial.
16. No update code path calls the bus unlock.

### Trial boot and rollback

17. `CONFIG_BOOTLOADER_APP_ROLLBACK_ENABLE=y`. The new image marks itself valid
    only after Wi-Fi, the broker (the command subscription is confirmed) and one
    successful bus query have all happened. Each is remembered once it has
    happened; they need not hold at the same moment. While the image is on trial
    the heartbeat says `trial: true` (ADR-0076).
18. The bootloader rolls back only on a reset, so the trial window is an
    **app-side timer**: 180 s (`LC_TRIAL_WINDOW_MS`) from the application's start
    on the clock port. If the three have not happened by then, or the valid mark
    cannot be stored, the app calls `esp_ota_mark_app_invalid_rollback_and_reboot()`,
    which restarts the unit and returns only on failure; if it returns, the app
    calls `esp_restart()`. After a USB flash `otadata` is empty and the first
    update works the same way (S7-H15).
19. Opens are allowed during the trial. The journal keeps them exactly once
    across a rollback reboot.

### Format compatibility across rollback

20. **Rule:** if image N+1 writes a new journal or NVS format and then rolls
    back, image N finds an unknown version and, by design, stops all actuation
    until the journal is erased over USB. Therefore a release that changes the
    journal or NVS format must ship only after a release that can already
    **read** the new format, and the writer must not switch formats until it has
    marked itself valid.
21. Today exactly one journal format and one NVS format exist, so the rule cannot
    fail yet and has no host test (S7-H16 from the issue is dropped). It is
    covered by this ADR and by hardware test HW-H11 (roll back an image that
    handled commands; the previous image reads the journal and keeps
    actuating). The rule becomes a host test with the first format change.

### Bootloader

22. The bootloader cannot be replaced over the air. It boots apps built with
    newer ESP-IDF versions but not older ones. The IDF version used to build the
    bootloader is pinned (6.1 today); later IDF upgrades happen in the app only.
    HW-H10 checks that the bootloader still boots the next patch release.

### Reporting

23. During and after an update the device publishes only what ADR-0076 defines:
    the one response, the `firmware_update_failed` event after an accepted
    update fails, and the heartbeat fields. It never publishes the URL, the token or image bytes.

The backend side (releases, download route, rollout tracking, timeout) is
[ADR-0077](0077-backend-firmware-releases-and-rollout-tracking.md).

## Rationale

Signature checks on update give protection against remote attackers now and keep
the secure-boot step an eFuse-only change later. HTTPS plus signing means a
compromised file host or a stolen token cannot install code. Trial boot with an
app-side timer is the only way to get time-based rollback from a bootloader that
rolls back on reset only. Ending on a heartbeat is the only proof that the new
image works.

## Alternatives Considered

### Alternative A: Hardware secure boot v2 and flash encryption now

- Pros: protects against physical flashing too.
- Cons: irreversible eFuses, key cannot be revoked on classic ESP32, no spare
  boards to waste before the pilot.
- Why not chosen: the signing format already supports it; the step comes with
  flash encryption (ADR-0074 decision 18).

### Alternative B: ECDSA signatures

- Pros: smaller signatures.
- Cons: ESP-IDF's ECDSA option uses the v1 format; moving to secure boot later
  means a new signing pipeline.
- Why not chosen: see decision 1.

### Alternative C: A custom verifier with a rotatable key in the app

- Pros: key rotation over the air.
- Cons: security-critical code of our own, and the bootloader would still not
  check it.
- Why not chosen: a leaked key is a USB visit; revisit if units are deployed at
  a scale where that hurts.

### Alternative D: HTTP download with a hash in the command

- Pros: no TLS memory cost.
- Cons: no server identity; hash and URL arrive on the same channel.
- Why not chosen: HTTPS with the CA bundle is already linked for MQTT.

### Alternative E: Download resume

- Pros: saves bandwidth on bad links.
- Cons: persistent state in NVS and a second path.
- Why not chosen: see decision 8.

## Consequences

### Positive

- Updates need no USB visit once the first OTA-capable image is flashed.
- A bad image, a lost link or a missing broker returns the unit to the old
  version unaided.

### Negative

- Units flashed with #321 need one USB flash to get the first OTA-capable image.
- Key loss or leak cannot be fixed over the air.
- Slot size (1.8 MB) is a hard limit; `verify.py` fails the build when an image
  outgrows it.

### Risks

- A leaked token before expiry lets someone download a (signed) image; it
  cannot install a different one. Mitigation: short lifetime, never logged.
- Rollback and the format rule: mitigated by decision 20 and HW-H11.
- Power cut during the write: the old slot is untouched; HW-H7 repeats it ten
  times.
- A good image rolls back if Wi-Fi or the broker is down during the whole trial
  window after the reboot. The old image then refuses that version
  (`UPDATE_VERSION_REJECTED`); recovery is a new release with the next patch
  version. The window deliberately keeps running without the network, because a
  broken image may be exactly the one that cannot reach it. Readiness refuses an
  update while the broker is not connected, so this needs an outage that starts
  during the reboot.
- **Open: release key custody.** Who holds the key and its offline backup, and
  whether an HSM is used. Needs an owner before the first release.
- **Open: bench key.** Proposal: a repository-held bench key for dev builds, so
  bench units update between bench images but never accept a release image, and
  the reverse.
- **Open: manifest polling.** Pull on command only (proposal) or also
  periodically.
- **Open: secure boot and flash encryption timing** (irreversible), and how
  encrypted OTA fits ADR-0074 decision 18.
- **Open: NVS format compatibility across future IDF versions** was not
  researched.
- **Open: timeout values.** Trial window 180 s is a proposal to tune on the
  bench.
- **Open: Pi `locker-client`.** Whether it later implements `update_firmware`
  (#70).

## Rollout / Migration

Slices: contract and ADRs (this), codec, application logic, ESP-IDF adapter and
build gates; the backend slices are in ADR-0077. Host tests S7-H01 to S7-H15 and hardware group H (HW-H1 to HW-H11) are
catalogued in `locker-firmware/docs/testing.md`. The first image of any bench or
pilot unit is flashed over USB, signed.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: #321 (firmware), #331 (SoftAP)
- Related issues: #56, #59, #70
- Related docs: [ADR-0069](0069-esp32-locker-firmware.md),
  [ADR-0070](0070-esp32-command-journal-record-format-and-retention.md),
  [ADR-0074](0074-esp32-softap-provisioning.md),
  [ADR-0076](0076-update-firmware-mqtt-command-and-heartbeat-fields.md),
  [ADR-0077](0077-backend-firmware-releases-and-rollout-tracking.md)
- ESP-IDF v6.1:
  [OTA](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/system/ota.html),
  [esp_https_ota](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/system/esp_https_ota.html),
  [Secure Boot v2](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/security/secure-boot-v2.html),
  [Kconfig reference](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/kconfig-reference.html)
