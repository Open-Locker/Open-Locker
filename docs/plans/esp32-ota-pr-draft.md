# Draft: ESP32 firmware OTA (#59), follow-up PR 3

Local draft, not an issue and not part of the plan yet. When agreed, the Slice 7
section moves into `esp32-rs485-hexagonal-client.md` next to Slice 6, and the PR
table in 2.1 gets a third row. Everything marked *proposal* is a decision for
the ADR, not a fact.

The flash layout is already set in PR 1 (#321) by ADR-0069: two OTA slots,
`otadata`, reserved `nvs_keys`, journal at the end, partition table at
`0xC000`. This PR changes no partition. It is still free to change the
bootloader configuration (rollback, signature checks) and the minimum chip
revision, because no unit is deployed. A unit flashed with PR 1 needs one USB
flash to get this firmware anyway: PR 1 has no OTA client, and the rollback
setting lives in the bootloader, which OTA cannot replace.

## PR title

`feat(firmware): OTA update with two slots and rollback (#59)`

Branch: `feat/59-firmware-ota`. Cut from `main` after PR 1 has merged; PR 2
(SoftAP) does not block it.

## PR description (draft)

Sub-issue of #56. Closes #59.

An enrolled ESP32 can replace its own firmware over HTTPS, boot the new image
on a trial basis, and go back to the old one if the new one does not prove
itself. Until now the only way to update a unit was flashing it over USB.

**In scope:** rollback over the two slots of ADR-0069; an HTTPS image download; image signature check; the on-device state machine; the
update command from #70 as far as the device is concerned; an ADR; the hardware
tests in Group H.

**Not in scope:** the backend side of #70 (heartbeat version field, rollout
status, admin UI), artifact hosting and the release workflow (ADR-0043 covers
`client-v*` Docker tags only), burning secure boot and flash encryption eFuses,
eFuse anti-rollback, delta updates.

**Safety rules, each held by a test:** an update never starts while a command is
in flight; the journal and the NVS identity survive every update and every
rollback; a failed or unverified image never replaces the working one; an update
never unlocks anything.

## Why this is a separate PR

- It sets the bootloader configuration (rollback, signature checks). The
  bootloader cannot be changed over the air later (ESP-IDF v5.4), so this is
  the last cheap moment to get it right.
- It needs its own ADR (signing, rollback, readiness) before any code,
  as the plan already states for #59.
- It depends on the update command designed in #70, which is still an open
  design issue for the Pi client. The ESP32 side must not invent a second
  contract.

## Blockers to settle first

1. **#70 contract.** Which MQTT command carries the update (`apply_update`?),
   what the heartbeat reports (installed version, capabilities), and how success
   is confirmed (a heartbeat with the expected version). The heartbeat also
   carries the security state the backend audits: running version and slot,
   whether the image is still on trial, the last rollback and its version, and
   the commissioning path (USB or portal) after a commissioning. Security
   events (refused image, rollback, factory reset) go to the backend audit log.
   Update `docs/asyncapi/` in the same PR or before it.
2. ~~**Journal retention.**~~ Settled in ADR-0068: the journal compacts and
   keeps the newest 256 delivered transactions, and the update command is
   journaled like any other command.
3. **Board hardware.** The layout (ADR-0069) needs 4 MB flash and the signing
   scheme needs chip revision v3.0 or later. Confirm both with `esptool.py flash_id` and
   `esptool.py chip_id` on every pilot board, and put both in the BOM as
   purchase requirements (ESP32-WROOM-32E/-32UE modules ship v3.x).
4. **Where images are hosted and who holds the signing key.** No release process
   for ESP32 images exists (ADR-0043 does not cover them).

## ADR to write first (first commit of the PR)

`docs/adr/NNNN-esp32-ota-update.md`, covering:

- The layout is ADR-0069; this ADR refers to it and adds the bootloader
  settings, which are fixed for the life of a unit once flashed.
- Image authenticity: *proposal* — RSA-3072 signatures in the Secure Boot v2
  format, verified by the running app on update, without enabling secure boot
  (`CONFIG_ESP32_REV_MIN_3`, `CONFIG_SECURE_SIGNED_APPS_NO_SECURE_BOOT`,
  `CONFIG_SECURE_SIGNED_ON_UPDATE_NO_SECURE_BOOT`,
  `SECURE_SIGNED_ON_BOOT_NO_SECURE_BOOT=n`). `esp_ota_end` and
  `esp_ota_set_boot_partition` check the signature; the bootloader does not.
  RSA over ECDSA because the ECDSA option uses the Secure Boot v1 format, which
  is a dead end: with RSA the same key and signature blocks are what Secure Boot
  v2 needs, so turning secure boot on later is an eFuse step, not a new signing
  pipeline. Key custody: the key is generated offline or in an HSM and never
  sits in CI secrets in plain form; images are signed outside the build
  (`espsecure.py sign_data`, which supports external signing). Write the
  generation, storage, backup and signing procedure into the ADR. This matters
  more than usual: once Secure Boot v2 is on, the ESP32 holds a single key that
  cannot be revoked. Until then, key rotation is an update signed with the old
  key that carries the new public key; recovery over USB if the key is
  compromised (no Espressif procedure exists, so this is our own scheme). This protects against a spoofed or tampered download, not against
  someone with physical access, since flash encryption is out of scope.
- Transport: HTTPS with the same CA bundle as MQTT (`crt_bundle_attach`);
  certificate verification cannot be turned off (as for the broker), and the
  build fails if `CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP` is set.
- Version policy: an explicit `PROJECT_VER` from the release tag (today
  `project_version` is "1"). Refuse an image equal to the running version, a
  downgrade unless the command says so, and the last version marked invalid.
  Compare through `esp_https_ota_get_img_desc` before any byte is written. No
  eFuse anti-rollback: it is irreversible per board and adds nothing while
  secure boot is off; the app-level guard covers a network attacker, who can
  only serve images we signed.
- Trial boot and rollback: *proposal* — `CONFIG_BOOTLOADER_APP_ROLLBACK_ENABLE`;
  the new image is marked valid only after it has joined Wi-Fi, reached the
  broker, and completed one successful bus query. The bootloader rolls back
  only on a reset while the image is still `PENDING_VERIFY`, so the trial window
  is an app-side timer that calls `esp_ota_mark_app_invalid_rollback_and_reboot()`
  and falls back to `esp_restart()` if that returns. During the trial the unit
  operates normally, opens included: the image is signed and has passed the
  hardware gate, and the journal keeps every command exactly-once across the
  rollback reboot (HW-H3). Refusing opens would turn every update into a
  visible outage.
- Readiness: what makes a device eligible to start an update (idle owner task,
  no command in flight, bus healthy, battery of retries exhausted).
- Failure reporting: what is published during and after an update, and what is
  never published (the URL token, if any).

## Flash layout

Set in PR 1 by [ADR-0069](../adr/0069-esp32-flash-layout.md) and
`locker-firmware/partitions.csv`: two equal OTA slots of 1.8 MB, no factory
slot, `otadata`, reserved `nvs_keys`, the journal at the end of flash, and the
partition table at `0xC000` so the bootloader has 44 KB. This PR uses the
slots and changes no partition.

## Slice 7: OTA (#59)

Scope:

- `lc_firmware_update_port_t`: the seam the plan promised (download, verify,
  select next slot, mark valid, roll back), with an ESP-IDF adapter
  `lc_ota_espidf` over `esp_https_ota` and `esp_ota_ops`. The adapter uses the
  advanced API (`esp_https_ota_begin`/`perform`/`finish`) in its own task, not
  the owner task, with `crt_bundle_attach`, checks the version through
  `esp_https_ota_get_img_desc` before `perform`, and restarts from byte 0 on
  failure (v5.4 has no download resumption).
- `lc_application`: an `update_firmware` use case — check readiness, start,
  report progress, restart, confirm after reboot.
- Codec: the update command and the version fields in the heartbeat, from #70.
- `sdkconfig.defaults` (minimum chip revision, rollback, signature checks),
  `PROJECT_VER`, and the README (signing, update procedure, recovery).

Host suite `test_firmware_update`, on the use case with a fake update port:

| ID | Test |
| --- | --- |
| S7-H01 | An update command with a valid signature field set reaches the update port with the URL and version from the command |
| S7-H02 | An update is refused while a command is in flight and starts only after it has completed |
| S7-H03 | An update is refused on an unhealthy journal or a disconnected bus |
| S7-H04 | An update to the running version, a downgrade without the explicit flag, and the last version marked invalid are refused |
| S7-H05 | A malformed command, a non-HTTPS URL, and an over-long URL are rejected with no download |
| S7-H06 | A failed download or a failed verification leaves the running slot selected and reports failure |
| S7-H07 | The next slot is selected only after the image is verified |
| S7-H08 | After a restart into a new image, the image is marked valid only when Wi-Fi, broker and one bus query have succeeded |
| S7-H09 | If any of them has not succeeded within the trial window, the image is marked invalid and the unit restarts |
| S7-H10 | No update path calls the bus unlock |
| S7-H11 | A repeated update command (same transaction ID) does not download twice |
| S7-H12 | No published message contains the download URL's query string or token |
| S7-H13 | Every emitted message validates against its schema in `docs/asyncapi/schemas` |
| S7-H14 | S1 to S5 tests pass unchanged |
| S7-H15 | The first update after a USB flash (empty `otadata`) rolls back when the trial fails, and falls back to `esp_restart()` if the rollback call returns |

CI gates: the build fails if `CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP` is set, and if
the bootloader or the app image outgrows its partition.

## Hardware tests (Group H)

Run after the existing groups pass on the PR head. Record them in
`hardware-record.json` as for PR 1.

| ID | Check |
| --- | --- |
| HW-H1 | Flash a blank board with `idf.py flash` (new bootloader with rollback and signature checks), enroll it, and rerun the regression checks HW-A1, HW-A6, HW-C4, HW-D2 and HW-D4 |
| HW-H2 | Update an idle unit through the backend: it reboots into the new version and the backend shows the version within the expected time |
| HW-H3 | **Actuates.** An open arriving during an update, and one during the trial window of a rollback, is handled once, never twice and never lost |
| HW-H4 | Serve an image with a bad signature, and one signed with another key: the device refuses both and keeps running the old version |
| HW-H5 | Serve a truncated image, then cut Wi-Fi mid-download: the old version keeps running and the device retries or reports failure per the ADR |
| HW-H6 | Serve a valid image that cannot join the broker: after the trial window the device returns to the old version unaided |
| HW-H7 | Cut power during the write of the new image, ten times: the device always boots a valid image |
| HW-H8 | After an update and after a rollback: identity, mapping and every journal record are intact, and the update itself caused no unlock |
| HW-H9 | Record minimum free heap during a download with the MQTT TLS session open, and confirm it leaves the owner task stack headroom of HW-C9 |
| HW-H10 | The bootloader flashed by this PR still boots an app built with the next IDF patch release |

## Open questions for review

- Should the first version support only pull-on-command, or also a periodic
  check against a manifest? (*Proposal:* command only; a manifest is a later
  decision.)
- When is secure boot v2 and flash encryption turned on (a provisioning step
  with irreversible eFuses)? This PR keeps the signing format ready for it.

## References

- #56, #59, #70; ADR-0005, ADR-0043, ADR-0068
- ESP-IDF v5.4: OTA, app rollback, signed apps, `esp_https_ota`, partition
  tables
- `docs/plans/esp32-ota-research.md` (primary-source check of an earlier version
  of this draft; its migration findings no longer apply)
- `docs/plans/esp32-security-research.md` (security beyond secure boot and flash
  encryption; source of the `nvs_keys`, key custody and heartbeat items)
