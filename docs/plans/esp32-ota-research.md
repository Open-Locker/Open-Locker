# Research: ESP32 OTA for `locker-firmware/` (input to #59 draft)

> Historical research, kept as written. It assumes the partition table at
> `0x8000` and proposes a layout of its own; the layout that ships is ADR-0069
> (table at `0xC000`, two OTA slots), and the journal is ADR-0068. Where this
> note and those ADRs differ, the ADRs apply.

Checks `docs/plans/esp32-ota-pr-draft.md` against Espressif primary sources for
the IDF version the repo pins: **ESP-IDF v5.4** (`README.md`, CI
`esp_idf_version: v5.4`, `sdkconfig` `CONFIG_IDF_INIT_VERSION="5.4.0"`). Target
`esp32`, `CONFIG_ESP32_REV_MIN=0`, no PSRAM, bootloader at 0x1000, partition
table at 0x8000. Measured from `build/`: app 1 007 760 B (0xf6090), bootloader
25 952 B. Sources were read on 2026-10-15.

## Summary and recommendation

The draft is close: HTTPS pull with `esp_https_ota`, app rollback, signed
images without secure boot, and a USB migration are all the right approach.
Change four things:

1. **Drop the factory partition.** Turn today's `factory` slot at 0x10000 into
   `ota_0` and give `ota_1` the same 0x180000. Each slot gets about 50 %
   headroom instead of 10 %. A factory image would never be updated, so rolling
   back to it could mean running very old firmware against today's NVS,
   journal and MQTT contract. Without a factory slot, anti-rollback also stays
   possible later.
2. **Have the app verify signatures on update, not the bootloader.** Use
   `CONFIG_SECURE_SIGNED_ON_UPDATE_NO_SECURE_BOOT`. Bootloader verification
   adds little without secure boot, is not available at all with the RSA
   scheme, and would use part of the ~2.7 KB of space left for the bootloader.
   Which scheme is possible depends on the chip revision, so confirm it first.
3. **Make the trial window an app-side timer.** Rollback happens only on a
   reset while the image is `PENDING_VERIFY`. The new image has to mark itself
   invalid or restart itself when its checks fail. Nothing else makes it roll
   back.
4. **USB migration is required, and it must also write a new bootloader.**
   OTA cannot replace the bootloader or the partition table in v5.4, and
   rollback is a bootloader feature.

Download over HTTPS, not in MQTT chunks. Do not use anti-rollback eFuses,
`esp_encrypted_img` or RainMaker for the pilot.

## 1. Partition layout

**Draft says:** keep `factory` (0x10000, 0x180000) and the journal at 0x190000.
Add `otadata` at 0x1d0000 (0x2000) and `ota_0` and `ota_1` of 0x110000 each,
ending exactly at 4 MB. Existing units need a one-time USB step.

**Primary sources say:**
- App partitions must start on a 64 KB (0x10000) boundary, and `otadata` should
  be 0x2000.
  [Partition tables, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-guides/partition-tables.html)
- The draft's table is valid. 0x1e0000 and 0x2f0000 are 64 KB aligned, and
  0x2f0000 + 0x110000 = 0x400000, so it ends exactly at 4 MB (my arithmetic).
  The 56 KB between 0x1d2000 and 0x1e0000 is unused.
- When `otadata` is empty, the bootloader boots `factory` if one exists, and
  otherwise the first OTA slot.
  [OTA, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/ota.html);
  `bootloader_utility.c` logs "Defaulting to factory image"
  ([source v5.4](https://github.com/espressif/esp-idf/blob/v5.4/components/bootloader_support/src/bootloader_utility.c)).
- The factory slot is never a rollback target: "Only OTA partitions can be
  rolled back. Factory partition is not rolled back." The bootloader falls back
  to factory only when both `otadata` entries are invalid. So on a long-lived
  unit, factory is the *first* image ever flashed.
  [OTA, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/ota.html)
- Anti-rollback "requires ota_0 + ota_1 without factory" (Kconfig
  `BOOTLOADER_APP_SECURE_VERSION`), and "Factory and Test partitions are not
  supported".
  [bootloader Kconfig v5.4](https://github.com/espressif/esp-idf/blob/v5.4/components/bootloader/Kconfig.projbuild),
  [OTA, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/ota.html)
- "The OTA ... process can flash new apps in the field but cannot flash a new
  bootloader."
  [Bootloader, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-guides/bootloader.html)
  v5.4 has no OTA path for the partition table. The `partitions_ota` example
  appears only in v5.5
  ([v5.4 examples](https://github.com/espressif/esp-idf/tree/v5.4/examples/system/ota),
  [v5.5 examples](https://github.com/espressif/esp-idf/tree/v5.5/examples/system/ota)).
  Its README says "Updating the bootloader and partition table is not safe" and
  "there is no rollback mechanism for non-application partitions"
  ([README v5.5](https://github.com/espressif/esp-idf/blob/v5.5/examples/system/ota/partitions_ota/README.md)).

**Recommendation:** use this table. It keeps every existing offset, and the
running image stays where it is (0x10000):

```csv
# Name,    Type, SubType, Offset,   Size
nvs,       data, nvs,     0x9000,   0x6000
phy_init,  data, phy,     0xf000,   0x1000
ota_0,     app,  ota_0,   0x10000,  0x180000
journal,   data, 0x40,    0x190000, 0x40000
otadata,   data, ota,     0x1d0000, 0x2000
ota_1,     app,  ota_1,   0x1e0000, 0x180000
# 0x360000 to 0x400000 (640 KB) left unallocated on purpose
```

- With empty `otadata` and no factory, the migrated unit boots `ota_0`, which
  is its current position (per the OTA page above).
- The USB migration is required: it writes the partition table, a new
  bootloader (section 3) and a signed `ota_0`. Do not erase `nvs` or `journal`.
  `idf.py flash` writes bootloader, partition table, `otadata` (initial,
  erased) and app only (unverified; check `build/flash_args` after the change).
- Confirm the flash size with `esptool.py flash_id` on every pilot board before
  accepting the table (the README still says 4 MB is assumed).

## 2. Signed images without secure boot

**Draft says:** `CONFIG_SECURE_SIGNED_APPS_NO_SECURE_BOOT` with the signature
"verified by the bootloader"; a key rotation story; no protection against
physical access.

**Primary sources say** ([bootloader Kconfig v5.4](https://github.com/espressif/esp-idf/blob/v5.4/components/bootloader/Kconfig.projbuild)):
- On ESP32 the RSA-3072 scheme (Secure Boot v2 format) requires
  `ESP32_REV_MIN_FULL >= 300`, that is chip revision v3.0 or later *and*
  `CONFIG_ESP32_REV_MIN_3` set. The repo has `CONFIG_ESP32_REV_MIN=0`, so
  today only the **ECDSA P-256 (Secure Boot v1 format)** scheme can be
  selected.
  Also [Secure Boot v2, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/security/secure-boot-v2.html).
- `SECURE_SIGNED_ON_BOOT_NO_SECURE_BOOT` (bootloader checks the signature)
  depends on the ECDSA scheme and defaults to `n`. Its help says that without
  hardware secure boot it adds little. `SECURE_SIGNED_ON_UPDATE_NO_SECURE_BOOT`
  defaults to `y` and "helps against network-based spoofing".
- In the app, `esp_image_format.c` checks the signature when
  `CONFIG_SECURE_SIGNED_ON_UPDATE` is set. `esp_ota_end()` and
  `esp_ota_set_boot_partition()` both run `esp_image_verify`, so an image that
  fails the check is never selected.
  [esp_image_format.c v5.4](https://github.com/espressif/esp-idf/blob/v5.4/components/bootloader_support/src/esp_image_format.c),
  [esp_ota_ops.c v5.4](https://github.com/espressif/esp-idf/blob/v5.4/components/app_update/esp_ota_ops.c)
- RSA without secure boot: "the public key that is present in the signature
  block of the currently running app will be used to verify" the new one. "It
  is essential that the initial app flashed to the device is also signed." Only
  the first signature block is checked.
  [Secure Boot v2, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/security/secure-boot-v2.html)
- ECDSA: the verification key "is compiled into the bootloader and/or app"
  (Kconfig `SECURE_BOOT_VERIFICATION_KEY`). The signature is 68 B. "For ESP32
  v3.0 onwards, the preferred secure boot scheme is Secure Boot v2."
  [Secure Boot v1, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/security/secure-boot-v1.html)
- "The device can be secured against remote network access, but not physical
  access" (same page). The draft's caveat is correct.
- Bootloader space: at the default table offset, the bootloader must fit
  before 0x8000, and the build fails if it does not. To make room you can raise
  `CONFIG_PARTITION_TABLE_OFFSET`.
  [Bootloader, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-guides/bootloader.html)
  Here, 0x1000 + 25 952 B leaves about 2.7 KB, and the table cannot move
  without moving `nvs` at 0x9000, which would lose enrolled identities (my
  arithmetic and inference).

**Recommendation:**
- Run `esptool.py chip_id` on the bench and pilot boards (it prints the chip
  revision).
- If every board is v3.0 or later, set `CONFIG_ESP32_REV_MIN_3` and use the
  RSA scheme. That is the same format that secure boot v2 uses later.
- Otherwise, use ECDSA.
- Either way, enable only on-update verification and leave
  `SECURE_SIGNED_ON_BOOT_NO_SECURE_BOOT=n`.
- The bootloader must be reflashed anyway for rollback (section 3), so the
  migration covers it.
- Measure the bootloader size after enabling rollback. If it gets close to the
  limit, set `CONFIG_BOOTLOADER_LOG_LEVEL_WARN`.
- Key rotation without secure boot: ship an image signed with the old key that
  trusts the new key. For RSA, put the new key in the *first* signature block
  of later images. For ECDSA, the new key is compiled into the next app. This
  is an inference from the chain-of-trust text and is **unverified**; Espressif
  documents no rotation procedure for this mode.
- If the private key leaks, every unit must be recovered over USB.
- Keep the private key out of the repo and CI logs. Sign with
  `espsecure.py sign_data` in the release job (`SECURE_BOOT_BUILD_SIGNED_BINARIES=n`).

## 3. Rollback

**Draft says:** `CONFIG_BOOTLOADER_APP_ROLLBACK_ENABLE`. The new image is marked
valid after Wi-Fi, broker and one bus query succeed; "otherwise the bootloader
returns to the previous slot at the next restart".

**Primary sources say:**
- How the states work:
  - A newly written image is `NEW`.
  - On its first boot, the bootloader sets it to `PENDING_VERIFY`.
  - "A reset occurs without calling either function then the application is
    rolled back." The image becomes `ABORTED`.
  - A power loss or watchdog reset during the first boot also rolls back.

  [OTA, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/ota.html);
  Kconfig help in
  [bootloader Kconfig v5.4](https://github.com/espressif/esp-idf/blob/v5.4/components/bootloader/Kconfig.projbuild)
- Nothing triggers a rollback while the app keeps running. A new image that
  boots, never reaches the broker and does not crash stays `PENDING_VERIFY`
  indefinitely (inference from the above). The app has to call
  `esp_ota_mark_app_invalid_rollback_and_reboot()` or restart itself.
- `esp_ota_begin()` refuses a new update while the running image is
  `PENDING_VERIFY` (`ESP_ERR_OTA_ROLLBACK_INVALID_STATE`).
  [esp_ota_ops.c v5.4](https://github.com/espressif/esp-idf/blob/v5.4/components/app_update/esp_ota_ops.c)
- `esp_ota_mark_app_invalid_rollback_and_reboot()` returns
  `ESP_ERR_OTA_ROLLBACK_FAILED` without rebooting when
  `esp_ota_check_rollback_is_possible()` is false. That check looks at the
  *other* `otadata` entry, and if that entry is invalid it falls back only to a
  factory partition. On the first update after migration (empty `otadata`, no
  factory), the other entry is still blank, so the call can fail even though a
  plain reset would boot `ota_0` (same source plus the bootloader rule in §1).
  This is an inference from source and **unverified on hardware**.
- Anti-rollback writes `secure_version` to eFuse, which cannot be undone. It
  needs coding scheme NONE and no factory partition. If an image's version is
  lower, it "will be erased" in `esp_ota_set_boot_partition`.
  [OTA, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/ota.html)

**Recommendation:**
- Enable rollback in `sdkconfig.defaults`.
- At start, if `esp_ota_get_state_partition(running)` is `PENDING_VERIFY`:
  - Start a trial timer, for example a few minutes; the ADR fixes the value.
  - When Wi-Fi, broker and one bus query all succeed, call
    `esp_ota_mark_app_valid_cancel_rollback()`.
  - When the timer expires, call `esp_ota_mark_app_invalid_rollback_and_reboot()`.
    If that call returns, call `esp_restart()`, since a reset while
    `PENDING_VERIFY` rolls back as well.
- While the trial runs, refuse all actuation, or at least refuse a new update.
  The ADR has to decide whether opens are allowed during the trial.
- No anti-rollback eFuse for the pilot. Use a software downgrade guard instead
  (§5).
- Keep the task watchdog panic on (it already is), so a hang in the trial image
  becomes a reset and therefore a rollback.

## 4. `esp_https_ota` API

**Draft says:** an adapter over `esp_https_ota` and `esp_ota_ops`; the same CA
bundle as MQTT; HW-H5 retries or reports failure.

**Primary sources say** ([esp_https_ota v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/esp_https_ota.html),
[esp_https_ota.c v5.4](https://github.com/espressif/esp-idf/blob/v5.4/components/esp_https_ota/src/esp_https_ota.c)):
- Advanced API:
  - Call `esp_https_ota_begin()` first.
  - Then call `esp_https_ota_get_img_desc()`. It is "called only after
    esp_https_ota_begin() and before esp_https_ota_perform()", so the version
    can be checked before anything is written.
  - Call `perform` in a loop; it returns after every HTTP read, which allows
    progress reporting and aborting.
  - End with `finish` or `abort`, never both.
- `finish` runs `esp_ota_end`, which verifies the image, then
  `esp_ota_set_boot_partition`. The caller must `esp_restart()`.
- Begin refuses to proceed unless `cert_pem`, `use_global_ca_store` or
  `crt_bundle_attach` is set, or `CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP` is enabled
  (default `n`; Kconfig says "ONLY FOR TESTING").
- The chip ID in the image header is checked before the first write.
- By default the partition is erased 4 KB at a time as data arrives
  (`OTA_WITH_SEQUENTIAL_WRITES`). `bulk_flash_erase` erases everything up front.
- `partial_http_download` with `max_http_request_size` fetches the image in
  Range requests within one session. Its stated purpose is lowering
  `CONFIG_MBEDTLS_SSL_IN_CONTENT_LEN` (example: 4 KB requests, "around 12 KB"
  saved). v5.4 has **no resumption across failures or reboots** (no
  `ota_resumption` field in the v5.4 source). A failed download starts again
  from byte 0.
- `CONFIG_ESP_HTTPS_OTA_DECRYPT_CB` is for pre-encrypted images
  (`esp_encrypted_img`). It is not needed.
- The running partition is never written (`ESP_ERR_OTA_PARTITION_CONFLICT`),
  and `otadata` changes only in `set_boot_partition`. A power cut or Wi-Fi loss
  mid-write therefore leaves the old image selected. `otadata` is two sectors
  "to prevent problems if there is a power failure while it is being written".
  [esp_ota_ops.c v5.4](https://github.com/espressif/esp-idf/blob/v5.4/components/app_update/esp_ota_ops.c),
  [OTA, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/ota.html)
- The journal and NVS are never touched by these APIs, which write only to app
  partitions and `otadata` (same sources). HW-H8 confirms this rather than
  being at risk.
- Heap: the current `sdkconfig` has `MBEDTLS_SSL_IN_CONTENT_LEN=16384`,
  `OUT=4096`, no dynamic buffer and no PSRAM, and a second TLS session would
  run alongside the MQTT one. The v5.4 RAM-usage page only points to mbedTLS
  heap options; no per-connection figure is published there
  ([RAM usage, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-guides/performance/ram-usage.html)).
  Treat the cost as **unverified** until HW-H9 measures it.

**Recommendation:**
- Use the advanced API with `crt_bundle_attach = esp_crt_bundle_attach`. That
  is the bundle already enabled for MQTT.
- Check the version with `get_img_desc` before `perform`.
- Run `perform` in its own task, not the owner task, so the owner task keeps
  feeding the watchdog and answering commands. Without that, the update cannot
  stay "refused while a command is in flight" and the owner task cannot stay
  responsive.
- Fall back to `partial_http_download` or `CONFIG_MBEDTLS_DYNAMIC_BUFFER`, or
  close MQTT during the download, only if HW-H9 shows too little heap.
- Retry by starting over, a bounded number of times.

## 5. Version comparison

**Primary sources say:** `esp_app_desc_t.version` is up to 32 characters. It is
taken from `PROJECT_VER` if set, then `version.txt`, then `git describe`, and
otherwise defaults to "1". It is read with `esp_app_get_description()` and
`esp_ota_get_partition_description()`.
[Misc system API, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/misc_system_api.html)
The current build's `project_description.json` shows `"project_version": "1"`,
so today no version is being set. The Docker build has no `.git`, so it falls
through to the default.
- The advanced example refuses an image whose version equals the running one
  ([advanced_https_ota v5.4](https://github.com/espressif/esp-idf/blob/v5.4/examples/system/ota/advanced_https_ota/main/advanced_https_ota_example.c)).
- The native example also refuses an image whose version equals the last
  invalid partition's (`esp_ota_get_last_invalid_partition`)
  ([native_ota_example v5.4](https://github.com/espressif/esp-idf/blob/v5.4/examples/system/ota/native_ota_example/main/native_ota_example.c)).

**Recommendation:**
- Set `PROJECT_VER` explicitly in `CMakeLists.txt` from the release tag (a
  strict `MAJOR.MINOR.PATCH`), not from `git describe`.
- Compare it numerically on the device.
- Refuse an image that is equal to the running version, older without an
  explicit downgrade flag, or equal to the last invalid image.
- The version is covered by the signature, because `esp_ota_end` verifies the
  whole image. The pre-write check only saves bandwidth.

## 6. Alternatives (short)

- **Image in MQTT chunks:** this has no ESP-IDF support. It would need
  reassembly, ordering and retry code on both sides, and it would load the
  broker. **Use an HTTPS URL** (`esp_https_ota`, shown above), with the URL in
  the authenticated MQTT command.
- **Signed manifest:** not needed for pull-on-command, because the image
  itself is signed and carries its version (§2, §5). Revisit only with periodic
  checks.
- **`esp_encrypted_img` / pre-encrypted OTA:** gives confidentiality only, and
  the device key "must be protected using platform security features" (flash
  encryption, out of scope)
  ([esp_https_ota v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/esp_https_ota.html)).
  Skip it.
- **ESP RainMaker / ESP Insights:** a separate cloud and control plane that
  overlaps with the backend and #70. Skip it (not researched further).

## 7. Wrong, risky or missing in the draft

- **Wrong:** "signature verified by the bootloader". It should be verification
  on update in the app (§2).
- **Wrong:** "the bootloader returns to the previous slot at the next restart".
  This is true only if something restarts the unit, so the app must
  self-restart (§3).
- **Risky:** 10 % slot headroom while `esp_http_client` and `esp_https_ota` are
  added to the image. Not measured; build once with OTA linked before fixing
  sizes. A no-factory layout gives 0x180000 slots (§1).
- **Risky:** stale factory image as the target of last resort (§1).
- **Missing:** the migration must also flash a **new bootloader** (rollback is
  configured in the bootloader) and a **signed** first app. Also, "the
  bootloader can boot apps built from newer ESP-IDF versions, but not older"
  ones, and it is frozen at whatever the migration writes.
  [Bootloader, v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-guides/bootloader.html)
  So pin the IDF version used for that bootloader, and keep later IDF upgrades
  in the app only.
- **Missing:** chip revision check (`esptool.py chip_id`) and flash check
  (`esptool.py flash_id`) on every pilot board, recorded in
  `hardware-record.json`.
- **Missing:** bootloader size budget (about 2.7 KB left; the partition table
  cannot move without moving NVS).
- **Missing:** a build-time gate that `CONFIG_ESP_HTTPS_OTA_ALLOW_HTTP` stays
  unset, the same as the existing `ESP_TLS_INSECURE` gate.
- **Missing:** a test for the first update after migration (empty `otadata`,
  rollback via `mark_invalid` returns an error, and the restart fallback works).
- **Missing:** a rule for what happens during the trial window: is actuation
  allowed, and is a second update refused? (The API already refuses an update
  while `PENDING_VERIFY`.)
- **Unclear:** the readiness item "battery of retries exhausted" needs
  rewording.
- **Security note:** no published ESP-IDF advisory (page 1 of
  [GitHub advisories](https://github.com/espressif/esp-idf/security/advisories),
  checked 2026-10-15) concerns OTA, `app_update`, `esp_https_ota` or image
  verification. Pages 2 and 3 were not checked. The lwIP DHCP-server advisory
  (GHSA-g764-gwc3-75m5) matters to the SoftAP PR, not this one. Pin the latest
  v5.4.x patch release (unverified which one contains which fix).
- **NVS compatibility:** the offsets stay the same, so NVS is untouched.
  Compatibility of the NVS *format* across future IDF versions was not
  researched (**unverified**).

## Concrete changes to the draft

1. Replace the flash layout with the no-factory table in §1. Rename
   "factory = image of last resort" to "ota_0 = today's slot".
2. ADR image authenticity: verify on update in the app
   (`SECURE_SIGNED_APPS_NO_SECURE_BOOT` + `SECURE_SIGNED_ON_UPDATE_NO_SECURE_BOOT`,
   `ON_BOOT=n`). Choose RSA if all chips are v3.0 or later
   (`CONFIG_ESP32_REV_MIN_3`), otherwise ECDSA. Sign outside the build. Rotate
   keys through an update signed with the old key, and recover over USB if the
   key is compromised.
3. ADR trial boot: an app-side timer that calls
   `esp_ota_mark_app_invalid_rollback_and_reboot()` and falls back to
   `esp_restart()`. State what is allowed while `PENDING_VERIFY`.
4. Migration section: the USB step writes bootloader, partition table, empty
   `otadata` and a signed `ota_0`. It never erases `nvs` or `journal`. Pin the
   IDF version for the bootloader.
5. Blocker 3: add `esptool.py flash_id` and `chip_id` per board, the measured
   image size with OTA linked, and the bootloader size.
6. Slice 7 adapter: the advanced `esp_https_ota` API in its own task,
   `crt_bundle_attach`, a version check through `get_img_desc` before
   `perform`, and a restart from zero on failure (no resume in v5.4).
7. Version policy: an explicit `PROJECT_VER` from the release tag. Refuse an
   image equal to the running version, a downgrade without the flag, or the
   last invalid version.
8. Tests:
   - Change S7-H09 to "image is marked invalid and the unit restarts".
   - Add S7-H15: a first update after migration rolls back with empty
     `otadata`.
   - Add a CI gate that HTTP OTA is disabled.
   - Add HW-H10: the bootloader of a migrated unit still boots after an app
     built with the next IDF patch release.
9. Open question 2: answer it in the ADR. On-update signing protects against
   network spoofing only, and physical access is out of scope.
