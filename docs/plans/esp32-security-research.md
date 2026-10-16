# Research: ESP32 firmware security beyond secure boot and flash encryption

> Historical research, kept as written. The flash layout it calls "PR 3 layout"
> is decided by ADR-0069 and already ships in PR 1; the journal by ADR-0068.
> Where this note and those ADRs differ, the ADRs apply.

Input to the plan in `esp32-rs485-hexagonal-client.md` (PR 2, SoftAP), the OTA
draft in `esp32-ota-pr-draft.md` (PR 3), and the "pilot gates" of #56. Secure
Boot v2 and flash encryption are taken as decided for later and are only
covered where they constrain other choices.

Sources are primary only: ESP-IDF v5.4 documentation, Espressif advisories,
Espressif repositories, the Mosquitto manual, Mbed TLS, ETSI, NIST, EU law.
"Kconfig of this build" means the generated `locker-firmware/sdkconfig`
(ESP-IDF 5.4.0, target `esp32`), which lists every option the v5.4 Kconfig
offers for this chip; it is used as evidence where the online Kconfig page
was too long to read. **Unverified** marks claims that were not confirmed
against a primary source in this session. Two links were cited but not
fetched: `mbedtls_config.h` (the meaning of `MBEDTLS_HAVE_TIME_DATE`, which
matches the ESP-IDF security page's SNTP note) and the `esp_crt_bundle` page.
The bundle options themselves are confirmed by the Kconfig of this build.

## Summary

The firmware is in good shape on the network side (verified TLS that cannot be
switched off, per-identity ACLs, journal-based exactly-once, no secrets in
replies or logs). The real gaps are local: **anyone with the USB port can
open every compartment in seconds** (two console commands, below), an
**enrolled unit can be pointed at another broker** and will hand it its MQTT
password, and **certificate expiry is not checked** although the code
believes it is. All three are small fixes and belong in PR 2.

Ranked by value for effort for the pilot:

| # | Recommendation | Protects against | Effort | When | Cite |
| --- | --- | --- | --- | --- | --- |
| 1 | Production builds drop the bench paths: no console `unlock`/`command` at all (Kconfig `LC_BENCH_CONSOLE`, off by default), and `reset-network` does not reopen them | USB attacker: `reset-network`, reboot, `unlock 1 0` opens any door | S | **Now, PR 2** | code refs §3 |
| 2 | Freeze the broker URL at enrollment: an enrolled unit accepts a new SSID/password only; a new broker needs reset and a new token | Credential theft: USB or portal user sets `mqtts://evil.example` with a public-CA cert; the device sends its MQTT password there | S | **Now, PR 2** | §2 |
| 3 | Turn on `CONFIG_MBEDTLS_HAVE_TIME_DATE` (the SNTP gate already exists) | Expired or not-yet-valid broker/OTA certificates are accepted today | XS | **Now, PR 2** | [Mbed TLS config](https://github.com/Mbed-TLS/mbedtls/blob/v3.6.0/include/mbedtls/mbedtls_config.h), [IDF security v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/security/security.html) |
| 4 | `CONFIG_COMPILER_STACK_CHECK_MODE_STRONG` | Stack smashing in parsers (MQTT JSON, console, portal) | XS | **Now** | [Kconfig v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/kconfig.html) |
| 5 | SoftAP via Espressif `network_provisioning` with Security 2 (SRP6a) and a per-device secret on a label/QR, WPA2 AP with a per-device passphrase, AP only when unprovisioned or after a held button at boot, 10 min timeout, one client | Nearby attacker joining the AP, sniffing the token, reconfiguring a unit | M | **PR 2** | §6 |
| 6 | Reserve a 4 KB `nvs_keys` partition (and an `nvs` large enough) in the PR 3 layout | NVS encryption on ESP32 needs it; the layout is frozen after PR 3 | XS | **Now, PR 3 layout** | [NVS encryption v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/storage/nvs_encryption.html) |
| 7 | Physical: USB-UART, RS485 converter and bus wiring inside the locked enclosure, no external USB; document it in the install guide | Direct bus unlock, flash readout, console abuse | S (docs/BOM) | **Now** | §3 |
| 8 | libFuzzer targets for `lc_mqtt_decode_command`, provisioning reply, `lc_console_parse_setup`, console line, RS485 codec, portal body parser, in the existing clang+ASan host build | Memory bugs at every trust boundary | S | **Now** | [libFuzzer](https://llvm.org/docs/LibFuzzer.html) |
| 9 | Pin ESP-IDF to the newest v5.4.x patch tag (CI uses `v5.4`, i.e. 5.4.0), run `esp-idf-sbom create/check` in CI, watch Espressif advisories | Known CVEs in IDF, mbedTLS, lwIP, Wi-Fi | S | **Now** | [esp-idf-sbom](https://github.com/espressif/esp-idf-sbom), [advisories](https://www.espressif.com/en/support/documents/advisories) |
| 10 | Signing key: generated offline, kept offline or in an HSM, signing via `idf.py secure-sign-data` outside CI; written custody procedure before the first signed image | Forged OTA images; on ESP32 Secure Boot v2 holds **one** key and cannot revoke it | S (process) | **Before PR 3 ships** | [Secure Boot v2 v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/security/secure-boot-v2.html) |
| 11 | Heartbeat reports firmware version, secure-boot/flash-encryption state, reset reason; device publishes security events (portal opened, reset, enrollment, console setup, TLS failures) | Fleet blind spots; attestation-lite | M | PR 3 / #70 | [NIST IR 8259A](https://nvlpubs.nist.gov/nistpubs/ir/2020/NIST.IR.8259A.pdf) |
| 12 | `SECURITY.md` with a vulnerability disclosure policy | ETSI EN 303 645 provision 5.2-1; CRA reporting | XS | **Now** | [ETSI EN 303 645 v3.1.3](https://www.etsi.org/deliver/etsi_en/303600_303699/303645/03.01.03_60/en_303645v030103p.pdf) |
| 13 | Mosquitto: `max_packet_size` / `message_size_limit` near the device's 4.6 KB buffer for device users, `persistent_client_expiration`; `auth_opt_log_level` not `debug` in production | Oversized payload DoS, stale sessions of revoked identities, credential material in broker logs (unverified) | XS | Now (backend) | [mosquitto.conf(5)](https://mosquitto.org/man/mosquitto-conf-5.html) |
| 14 | Reject `open_compartment` whose `timestamp` is older than a bound (e.g. 60 s) once the clock is valid | Late or replayed opens after a long broker delay | S, contract change | Later, ADR | §2 |
| 15 | Backend-initiated credential rotation over MQTT | Long-lived password; today rotation needs a site visit | M | Later, ADR | §1 |
| 16 | Secure Boot v2 + flash encryption (release) + NVS encryption + ROM download disabled + JTAG off | Flash readout, firmware replacement | L, irreversible | Later (planned) | §3 |

## 1. Device identity and credentials

**Current state.** `lc_identity_t` (`components/lc_domain/include/lc_domain/identity.h`)
holds a random client ID (`esp32-` + 8 random bytes, `main/app_main.c:120`), the
bootstrap user/password and one-time token until enrollment, then the issued
`mqtt_user`/`mqtt_password` and locker UUID. `lc_network_settings_t` holds the
site Wi-Fi SSID/password and broker URL. All are plain NVS blobs in namespace
`locker` (`components/lc_storage_nvs/nvs_stores.c`); the Wi-Fi driver keeps no
copy (`WIFI_STORAGE_RAM`, `lc_net_wifi/wifi.c`). The backend issues a 32-char
random username and password per accepted provisioning, stores a hash, and
revokes all live identities on reset (`app/Reactors/MqttReactor.php:68`,
`app/Services/MqttUserService.php`, ADR-0050). The provisioning token is
64 random chars, stored as an HMAC, one-time (ADR-0048), with **no expiry**
(`LockerProvisioningService::acceptRegistration` checks only the HMAC and
generation). The bootstrap MQTT user is **shared by all devices** but can only
publish `locker/register/+` and subscribe `locker/provisioning/reply/%c`
(`MosquittoAuthController.php:90-118`).

**Primary sources say.**
- NVS encryption has two key-protection schemes: flash-encryption-based
  (`nvs_keys` partition, flash encryption required) and HMAC-based
  ([NVS encryption v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/storage/nvs_encryption.html)).
  The ESP32 classic has **no HMAC and no Digital Signature peripheral**: the
  Kconfig of this build has no `SOC_HMAC_SUPPORTED`, `SOC_DIG_SIGN_SUPPORTED`
  or `NVS_SEC_KEY_PROTECTION_SCHEME` entries, while it has
  `SOC_FLASH_ENC_SUPPORTED`. So on this chip NVS encryption = flash encryption.
- "Default NVS data, including Wi-Fi credentials, is otherwise unprotected"
  ([security overview v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/security/security.html)).
- ETSI EN 303 645 5.1-1 (unique per-device credentials), 5.4-1 (store sensitive
  security parameters securely), 5.4-3 (no hard-coded critical security
  parameters in device software)
  ([EN 303 645 v3.1.3](https://www.etsi.org/deliver/etsi_en/303600_303699/303645/03.01.03_60/en_303645v030103p.pdf);
  provision wording not re-read this session, **unverified**).

**Recommendation.**
- Keep username/password, not mTLS, for the pilot. ADR-0053 terminates TLS at
  Traefik and forwards plain MQTT, so a client certificate would not reach
  Mosquitto/go-auth; mTLS means TLS passthrough or cert-to-username mapping at
  Traefik, an infrastructure ADR of its own. Per-device passwords already meet
  "unique per device".
- Until flash encryption: accept that physical access to the board = all
  secrets (MQTT password, **site Wi-Fi PSK**). Mitigate by enclosure (§3), by
  making revocation fast (admin "reset provisioning" already revokes, ADR-0050),
  and by telling site owners the PSK is on the device (guest/IoT VLAN SSID).
- Reserve `nvs_keys` (4 KB, `encrypted`) in the PR 3 layout now so NVS
  encryption is a config change later, not a layout change.
- Add a token expiry (e.g. 24 h) in the backend; cheap, limits a lost label or
  screenshot. Backend change, can ride on PR 2's ADR.
- Rotation (later): a backend command `rotate_credentials` delivering a new
  password, device saves then confirms, backend revokes old after confirm.
  Needs an ADR (MQTT contract).
- The shared bootstrap user is acceptable (it can only spend a token it already
  knows), but make it rotatable server-side and do not print it on labels; with
  `network_provisioning` it can be compiled in or supplied by the app.

## 2. Transport

**Current state.** MQTT 3.1.1 over TLS 1.2 with the full Mozilla CA bundle
(`CONFIG_MBEDTLS_CERTIFICATE_BUNDLE_DEFAULT_FULL`), verification enforced at
compile time (`lc_mqtt_espidf/transport.c:9-11`), `mqtts://` enforced
(`commission_device.c`). The session starts only after SNTP set the clock
(`main/owner_task.c:82`, comment: "The certificate check needs the date").
**But `CONFIG_MBEDTLS_HAVE_TIME_DATE` is not set** (sdkconfig line 1535), so
mbedTLS does not check the certificate validity period at all. SNTP is plain
`pool.ntp.org`. ACLs are scoped per identity via `%l` = the identity's mapped
locker UUID, matched literally; devices may publish only
`locker/<uuid>/state/#|response|event` and subscribe only
`locker/<uuid>/command` (`MosquittoAuthController.php:125-157`,
`MqttAclService.php`). Dedupe: message ID and transaction ID in the journal
(`handle_command.c:104-118`, ADR-0002, ADR-0068). Command `timestamp` is
checked for syntax only (`codec.c:257`). Commands queued in the persistent
session before SUBACK are dropped (S3-H11).

**Finding: the broker URL can be changed on an enrolled unit.**
`lc_commission_apply` takes the network fields, including `broker_url`, on an
enrolled device (S5-H04). With the full public CA bundle, anyone who can reach
the console (or, later, the portal) can set `mqtts://<their domain>` with a
free public certificate; the device then authenticates there with its issued
MQTT username and password. Hostname verification does not help because the
attacker owns that hostname.

**Primary sources say.**
- "Verify server identity with X.509 certificates, using the certificate bundle
  where possible"; "Manage root certificate updates carefully"; if
  `CONFIG_MBEDTLS_HAVE_TIME_DATE` is enabled, provide SNTP
  ([security overview v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/security/security.html)).
- `MBEDTLS_HAVE_TIME_DATE` is what enables X.509 validity-period checks
  ([mbedtls_config.h](https://github.com/Mbed-TLS/mbedtls/blob/v3.6.0/include/mbedtls/mbedtls_config.h)).
- Bundle options in v5.4: full, common (`..._DEFAULT_CMN`), none, plus
  `CONFIG_MBEDTLS_CUSTOM_CERTIFICATE_BUNDLE` (Kconfig of this build;
  [esp_crt_bundle v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/protocols/esp_crt_bundle.html)).
- `CONFIG_MBEDTLS_SSL_PROTO_TLS1_3` exists in v5.4 (Kconfig of this build).

**Recommendation.**
- Freeze `broker_url` after enrollment (rank 2). Store it with the identity;
  on an enrolled unit, setup requests change SSID/password only; S5-H04/H05
  and S6 tests get a case "broker change on enrolled unit is refused".
- Enable `CONFIG_MBEDTLS_HAVE_TIME_DATE` (rank 3). The existing time gate
  makes it safe; also fix the owner_task comment, which is currently wrong.
- Optional, cheap: restrict the bundle to the CAs Traefik/ACME actually uses
  (custom bundle with ISRG Root X1 and X2 plus one fallback CA). Weigh against
  the "root updates" warning: a wrong bundle bricks MQTT and OTA. With rank 2
  in place this is defence in depth, not a must.
- SNTP spoofing can only roll time; with rank 3 the worst case is refusing to
  connect or accepting a recently expired cert. Acceptable; no NTS on IDF.
- TLS 1.3: not needed for the pilot (Traefik negotiates 1.2 fine; 1.3 costs
  RAM). Revisit with HW-C9 heap numbers.
- SNI: esp-tls sends the URI host as SNI and Traefik routes on `HostSNI`
  (ADR-0053); already proven by HW-C1. Nothing to do.
- Replay: transport replay is covered by TLS; broker-side replay is covered by
  the journal. Add a staleness bound on `open_compartment` (rank 14) only with
  an ADR, as it changes the contract and relies on device time.
- Station Wi-Fi: `threshold.authmode = WPA2_PSK`, PMF capable, not required
  (`wifi.c`). Correct for unknown site APs: PMF Required would refuse non-PMF
  APs ([Wi-Fi security v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-guides/wifi-security.html)).
  Open site networks are allowed; TLS still protects MQTT. Keep.

## 3. Debug and physical interfaces

**Current state.** UART0 is both the ROM download port and the line console
(`sdkconfig`: `ESP_CONSOLE_UART`, no download-mode restriction, JTAG eFuse not
burned). Console commands (`lc_usb_console/line.c:183-212`, `console.c`):
`status`, `diagnostics`, `self-test`, `query`, `configure <json>`,
`reset-network`, and the bench-only `unlock` and `command`, allowed while
`!enrolled && !configured` (`console.c:73-86`, `main/console_glue.c:24-37`).
Replies never echo submitted values (S5-H14) and no log line prints a secret
(checked: no `ESP_LOG` with token/password/user).

**Finding: USB console to unlock in two commands.** `reset-network` is
unauthenticated and always accepted; `lc_commission_reset` clears identity,
mapping and settings and restarts (`commission_device.c:126-145`). After the
restart the device is neither enrolled nor configured, so bench `unlock <board>
<address>` is allowed and actuates directly, without journal or backend. The
unit goes offline in the backend (Last Will), which is the only trace. With
download mode open, `esptool.py` flashing own firmware or reading flash is
equally possible, so the root issue is physical reachability of UART0; but the
console makes it a 10-second job with a phone and an OTG cable.

**Primary sources say.**
- JTAG is disabled by the bootloader on first boot when secure boot or flash
  encryption is enabled unless `CONFIG_SECURE_BOOT_ALLOW_JTAG`; eFuse
  `JTAG_DISABLE` (BLK0 bit 198) does it on its own
  ([Kconfig v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/kconfig.html),
  [eFuse v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/efuse.html)).
- "Secure Download mode is not available for ESP32"; the only options are
  permanently disabled (`UART_DOWNLOAD_DIS`, v3.0+ only) or enabled; once
  disabled, "esptool.py can not work on the device" (same pages,
  [security overview](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/security/security.html)).
  So on this chip, locking download mode means **no USB recovery ever**, OTA
  only.
- `CONFIG_EFUSE_VIRTUAL` lets eFuse flows be tested without burning
  ([eFuse v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/efuse.html)).
- Even with Secure Boot v2 + flash encryption in release mode and download
  mode disabled, EMFI on ESP32 v3.0 can jump into ROM download mode
  (AR2023-005, CVE-2023-35818); AR2022-003 breaks the AES core of v3.0 by side
  channel; v0/v1 are broken by voltage glitching (AR2020-001)
  ([advisories](https://www.espressif.com/en/support/documents/advisories)).
  Treat the chip as not resistant to a lab attacker.
- ETSI EN 303 645 5.6-1/5.6-4: disable unused interfaces, and disable
  physically accessible debug interfaces in software (**unverified** wording).

**Recommendation.**
- Rank 1: a Kconfig `LC_BENCH_CONSOLE` (default `n`) that compiles in
  `unlock` and `command`; production images do not have them at all. Keep
  `status`, `diagnostics`, `self-test`, `query` (read-only). Make
  `reset-network` require a physical action as well (button held at boot, see
  §6), so a console alone cannot wipe and take over a unit.
- After SoftAP lands, remove `configure` from production builds (plan already
  recommends this, slice 6).
- Rank 7: UART0, the RS485 converter and the A/B pair stay inside the locked
  enclosure; no external USB. Anyone on the RS485 pair can send an unlock frame
  (the panel protocol has no authentication), so the bus is as sensitive as the
  lock wiring itself. A door/tamper switch wired to a spare input and reported
  as an event is a later, cheap hardware add; the existing uncommanded-open
  event (S4-H05) already covers doors opened by a bus attack.
- Logs: keep `INFO`; consider `CONFIG_BOOTLOADER_LOG_LEVEL_WARN`. No secrets
  are logged today; add a host test that greps formatted log output in the
  fakes for the submitted secrets (cheap regression guard).
- Later, with secure boot: burn `JTAG_DISABLE` and decide on
  `UART_DOWNLOAD_DIS` knowing it removes USB recovery; leave
  `CONSOLE_DEBUG_DISABLE` default.

## 4. Firmware hardening

**Current state.** Task WDT with panic, interrupt WDT (`sdkconfig.defaults`);
FreeRTOS stack overflow check = canary; assertions enabled; panic = print and
reboot; core dump off; `CONFIG_COMPILER_STACK_CHECK_MODE_NONE`. `-Werror` on
`main`. Payload size, UTF-8, NUL and identifier checks in the codec
(`codec.c`, `lc_codec_payload_is_acceptable`), bounded console lines (S5-H10 to
H15). Host tests run under ASan/UBSan (`tests/host/CMakeLists.txt:98-101`). CI
pins `esp_idf_version: v5.4` (`.github/workflows/locker-firmware-ci.yml:64`);
`CONFIG_IDF_INIT_VERSION="5.4.0"`.

**Primary sources say.**
- Stack check modes: none, normal (`-fstack-protector`), strong
  (`-fstack-protector-strong`), all
  ([Kconfig v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/kconfig.html)).
- Memory protection (`ESP_SYSTEM_MEMPROT`) and the hardware stack guard are not
  offered for `esp32` (absent from the Kconfig of this build; **unverified**
  in prose docs).
- "Update regularly to the latest bugfix release"; IDF component advisories are
  on GitHub ([security overview](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/security/security.html)).
- `esp-idf-sbom create build/project_description.json | esp-idf-sbom check`
  produces SPDX/CycloneDX and checks NVD
  ([esp-idf-sbom](https://github.com/espressif/esp-idf-sbom)).

**Recommendation.**
- Rank 4: `CONFIG_COMPILER_STACK_CHECK_MODE_STRONG` in `sdkconfig.defaults`;
  measure image size and the HW-A9 timings once.
- Rank 8: one libFuzzer harness per parser, built only with clang in the host
  gate (`-fsanitize=fuzzer,address,undefined`), seeded from the golden frames
  and AsyncAPI examples; run 60 s per target in CI, longer nightly.
- Rank 9: pin the CI image to an explicit patch tag (newest v5.4.x;
  **unverified** which is current) and bump on advisories; SBOM check as a
  non-blocking CI job first. Note ESP-IDF v5.2 went EOL 2026-09-15 (AR2026-008);
  check v5.4's end of support before the pilot (**unverified** date).
- Core dump to flash: useful for field faults but writes RAM (secrets) to
  flash; enable only together with flash encryption.
- Heap/stack: already measured in HW-C9/HW-G1; add the minimum free heap and
  owner-task high-water mark to the heartbeat (pairs with rank 11).

## 5. OTA-specific (PR 3)

**Current state.** The draft already has RSA-3072 Secure-Boot-v2-format
signatures verified on update, app-level anti-rollback by version, HTTPS with
the bundle, no `ALLOW_HTTP`, and S7-H12 (no URL token in any message).

**Primary sources say.**
- With `CONFIG_SECURE_BOOT_BUILD_SIGNED_BINARIES` off, signing happens off the
  build machine (`idf.py secure-sign-data`); an HSM may hold the key; "Keep the
  signing key private at all times"
  ([Secure Boot v2 v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/security/secure-boot-v2.html)).
- On ESP32, "Only one public key can be generated and stored in the chip"
  (same page): no key revocation once secure boot is on.

**Recommendation.**
- Rank 10: key generated on an offline machine (or in an HSM), one encrypted
  offline backup, signing as a manual release step outside CI; record who holds
  it. A compromised key after secure boot is burned means replacing chips.
- Rank 3 (validity dates) also protects the OTA download.
- URL token: short-lived (minutes), single-image scope, delivered only in the
  `locker/<uuid>/command` message (already ACL-private); never in heartbeat,
  response, or logs (S7-H12 covers messages; add logs).
- Add `nvs_keys` to the layout (rank 6).
- eFuse anti-rollback stays out until secure boot, as the draft says.

## 6. SoftAP commissioning (#57, PR 2)

### Threat model

| Threat | Today's plan (slice 6) | Gap |
| --- | --- | --- |
| Nearby attacker joins the AP and submits settings first | "portal credential defined in the ADR" (S6-H03) | Undefined |
| Sniffing token/Wi-Fi PSK over the air | not decided | Open AP or WPA2 with a shared password leaks both |
| Captive-portal phishing (evil twin with same SSID harvests the installer's token) | not addressed | HTTP portal cannot authenticate the device to the phone |
| Reconfiguring an enrolled unit (new broker, see §2) | S6-H06: not when enrolled **and online** | Offline enrolled units would open the portal |
| Portal as unlock path | S6-H09 forbids status/diagnostics | Make "no unlock" explicit |
| DoS / flooding the HTTP server | S6-H02 size limits | Socket and rate limits |

### What primary sources offer

- ESP-IDF provisioning (`wifi_provisioning` in v5.4, moved to the component
  registry as `espressif/network_provisioning`, backward-compatible protos)
  supports SoftAP and BLE transports and protocomm security 0/1/2. Security 2 is
  "SRP6a-based shared key derivation" with AES-256-GCM; salt and verifier are
  generated outside the device, the password given to the phone "e.g., QR code
  sticker"; for SoftAP it suggests WPA2 with a unique per-device passphrase
  ([provisioning v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/provisioning/provisioning.html),
  [protocomm v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/provisioning/protocomm.html),
  [wifi_provisioning v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/provisioning/wifi_provisioning.html),
  [network_provisioning](https://components.espressif.com/components/espressif/network_provisioning)).
- Custom endpoints (`wifi_prov_mgr_endpoint_create/register`) carry extra data
  (here: broker URL, token) after session setup (same page). That they are
  encrypted by the session like the Wi-Fi config is how protocomm works, but
  the page does not state it: **verify** with `esp_prov` and a packet capture.
- The SoftAP transport is plain HTTP; security comes from protocomm, not TLS
  (same page). Auto-stop after success and a 30 s grace; failed attempts keep
  the service running (same page).
- Espressif ships native Android and iOS provisioning libraries
  ([esp-idf-provisioning-android](https://github.com/espressif/esp-idf-provisioning-android),
  [esp-idf-provisioning-ios](https://github.com/espressif/esp-idf-provisioning-ios))
  and the `esp_prov` Python tool. **No Espressif-maintained React Native
  library exists**; only community wrappers around the native libraries
  (not evaluated as sources).
- SoftAP: WPA2/WPA3 via `authmode`, WPA3 SoftAP needs
  `CONFIG_ESP_WIFI_SOFTAP_SAE_SUPPORT` (+6.5 KB, already `y` in this build) and
  forces PMF required; `max_connection` default 10
  ([Wi-Fi security v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-guides/wifi-security.html),
  [Wi-Fi guide v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-guides/wifi.html)).
- `esp_http_server` limits: `max_open_sockets`, `recv_wait_timeout`,
  `lru_purge_enable`, header/URI caps (`CONFIG_HTTPD_MAX_REQ_HDR_LEN` 512 here)
  ([esp_http_server v5.4](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/protocols/esp_http_server.html)).

### Custom captive portal vs `network_provisioning`

| | Custom HTTP captive portal | `network_provisioning`, SoftAP, Security 2 |
| --- | --- | --- |
| Confidentiality of token/PSK | Only WPA2 of the AP; HTTPS would need a self-signed cert every phone warns about | SRP6a session (AES-GCM) on top of WPA2 |
| Device authenticates to installer | No (evil twin indistinguishable) | Yes: only the device that knows the verifier for the label password completes SRP |
| Phone side | Any browser, no app | Needs app support (native Espressif libs, `esp_prov`, Espressif demo apps); RN needs a native bridge |
| Brute force of label secret | Online guessing against the portal | SRP verifier; online guessing only, rate-limit sessions |
| Code to own | HTTP server, form parsing, captive DNS, HTML | Espressif component + one custom endpoint |
| Fits `commission_device.c` seam | Yes | Yes (adapter maps endpoint data to `lc_setup_request_t`) |

Choose `network_provisioning` with Security 2. The mobile app integration can
come later: for the pilot, installers use `esp_prov` (Python, same as
`commission.py` today) or a thin native bridge to the Espressif libraries.

### Recommended flow

1. **Factory/bench:** at first flash, `commission.py`-style tooling generates a
   random per-device SRP password (≥ 12 chars) and AP passphrase, writes the
   salt/verifier and AP passphrase to NVS (not the password), and prints a
   label/QR with device ID, SSID `OL-<id>`, AP passphrase and SRP password.
   Per ETSI 5.1-1, no shared default. (Alternative: derive nothing; random only.)
2. **When the AP may run:** only (a) when the unit has no stored settings, or
   (b) when a button on the board is held for 5 s during boot. Never on a
   running unit, never from MQTT, never from the console alone. An enrolled
   unit that loses Wi-Fi does **not** open the AP on its own (change S6-H08:
   it keeps retrying; the installer uses the button).
3. **AP settings:** WPA2-PSK (or WPA2/WPA3 mixed if the phone mix allows),
   per-device passphrase, `max_connection = 1`, PMF optional, channel follows
   STA. Window: 10 minutes after start, then the AP and HTTP server stop; a
   reboot without button press does not reopen it.
4. **Session:** Security 2 with the label password. Limit failed SRP sessions
   (e.g. 5 per window, then close the window). One custom endpoint
   `ol-config` carries broker URL, bootstrap user/password and token; Wi-Fi
   via the standard endpoint.
5. **Enrolled units:** the custom endpoint accepts only SSID/password; broker
   URL and token fields are refused (§2). The portal never exposes status,
   diagnostics, stored values, or any unlock action (S6-H09 plus a new
   "no unlock" test).
6. **Factory reset:** button held 10 s at boot (physical presence) wipes
   identity, mapping and settings, keeps the journal (same as S5-H07), and
   publishes nothing; the backend learns from the Last Will and a fresh
   enrollment. `reset-network` over USB follows the same rule (§3).
7. **Commands during the window:** on a previously enrolled unit (case b) the
   unit stops taking commands while the AP is up (HW-F5 decides; recommend
   refuse, since the device restarts after saving anyway).
8. **Audit:** after it is online again, the device reports "commissioned via
   portal at <boot count>" in its first heartbeat or an event (rank 11).

USB commissioning: keep `configure` only in bench builds once PR 2 lands, as
the plan already recommends.

New/changed tests for slice 6: portal refuses broker/token on an enrolled unit;
AP does not start on an enrolled offline unit without the button; window
closes after timeout; second station refused; N failed sessions close the
window; no response contains any value (S6-H04 kept); no code path from the
portal reaches the bus port.

## 7. Fleet and operations

**Current state.** Heartbeat carries uptime and bus state only; uncommanded
opens and LWT are reported; admin audit log exists in the backend (ADR-0030).
No `SECURITY.md` in the repo. Mosquitto: no packet/payload limits, persistent
sessions never expire, go-auth at `debug` log level
(`locker-backend/mosquitto/mosquitto.conf.template`).

**Primary sources say.**
- NIST IR 8259A core baseline: device identification, device configuration,
  data protection, logical access to interfaces, software update, cybersecurity
  state awareness
  ([NIST IR 8259A](https://nvlpubs.nist.gov/nistpubs/ir/2020/NIST.IR.8259A.pdf)).
- ETSI EN 303 645 5.2-1: publish a vulnerability disclosure policy
  ([EN 303 645 v3.1.3](https://www.etsi.org/deliver/etsi_en/303600_303699/303645/03.01.03_60/en_303645v030103p.pdf)).
- Mosquitto `max_packet_size` (default 2 MB since 2.1), `message_size_limit`
  (default no limit), `persistent_client_expiration` (default never)
  ([mosquitto.conf(5)](https://mosquitto.org/man/mosquitto-conf-5.html)).
- EU Cyber Resilience Act, Regulation (EU) 2024/2847
  ([EUR-Lex](https://eur-lex.europa.eu/eli/reg/2024/2847/oj/eng)): applies to
  products with digital elements placed on the EU market; vulnerability and
  incident reporting from 11 Sep 2026, the rest from 11 Dec 2027 (dates
  **unverified** this session; page did not load). Relevant only if Open-Locker
  units are sold or provided commercially; a free open-source project outside
  commercial activity is treated differently.

**Recommendation.**
- Rank 11, via #70/PR 3: heartbeat fields `firmware_version`, `secure_boot`,
  `flash_encryption` (from `esp_secure_boot_enabled()` /
  `esp_flash_encryption_enabled()`), `reset_reason`, `min_free_heap`. This is
  self-reported, not attestation (no hardware root of trust is active);
  label it so.
- Security events: portal opened/closed, factory reset, enrollment result,
  repeated TLS failures, console setup; backend writes them to the audit log.
- Rank 12: `SECURITY.md` with contact, scope, response time.
- Rank 13: per-listener `max_packet_size` (e.g. 16 KB) and
  `persistent_client_expiration` (e.g. 30 d); set go-auth log level to `info`
  in production (whether `debug` logs passwords is **unverified**).
- Decommissioning: admin "reset provisioning" (revokes credentials) + physical
  factory reset (button) + `esptool.py erase_flash` before reuse or disposal;
  document it in the README next to "returning a bank to the Pi client".

## Concrete changes to plans

| Change | Where |
| --- | --- |
| Kconfig `LC_BENCH_CONSOLE` (default off) gating `unlock`/`command`; `reset-network` requires the boot button | PR 2 (slice 6 scope + tests), small change to `lc_usb_console`, `console_glue.c` |
| Broker URL frozen after enrollment; tests in S5/S6 | PR 2; note in portal ADR |
| `CONFIG_MBEDTLS_HAVE_TIME_DATE=y`, `CONFIG_COMPILER_STACK_CHECK_MODE_STRONG=y`; fix owner_task comment | PR 2 (or a small standalone PR before it) |
| Portal ADR decides: `network_provisioning` + Security 2, per-device label secret, AP rules, window, button, enrolled-unit rules, factory reset | PR 2, first commit (ADR as the plan requires) |
| S6-H08 changed (no auto portal on enrolled unit), new S6 tests listed in §6, HW-F additions: second station refused, timeout, wrong SRP password | PR 2 |
| Token expiry in `LockerProvisioningService` | PR 2 backend part, or separate backend PR; mention in ADR |
| `nvs_keys` partition in the frozen layout | PR 3 layout proposal |
| Signing key custody procedure; key generated offline/HSM | PR 3 ADR |
| Heartbeat version/security fields, security events | PR 3 / #70 contract (AsyncAPI, ADR) |
| libFuzzer targets, IDF patch pin, `esp-idf-sbom` job | New small PR (firmware CI), any time |
| `SECURITY.md`; Mosquitto limits and log level | New small PR (repo/backend) |
| Command staleness bound; credential rotation command; mTLS | Later, each its own ADR |
| Secure boot v2, flash encryption release, NVS encryption, JTAG/download eFuses, enclosure tamper input | Later, "pilot gates" of #56; one ADR for the eFuse provisioning step, decide on USB recovery loss |
