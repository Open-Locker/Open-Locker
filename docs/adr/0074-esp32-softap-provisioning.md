# ADR-0074: ESP32 SoftAP provisioning and 24-hour provisioning tokens

## Status

Proposed

## Date

2026-10-19

## Context

`locker-firmware/` is commissioned over USB with `scripts/commission.py`. That
needs a cable and a computer with ESP-IDF tools at every unit. Issue #57 asks for
commissioning with no cable: the unit opens a setup Wi-Fi network (SoftAP), an
installer joins it and hands over Wi-Fi settings and the one-time provisioning
token.

A setup network is an attack surface that USB is not. Anyone in radio range can
see it. The inputs are secrets (Wi-Fi password, bootstrap login, token). The
unit later actuates locks. The design below limits when the network exists, who
can use it, and what it can reach.

Today the backend's provisioning token never expires. A token printed or shown in
the admin panel stays valid until it is used or the bank is reset. A longer
commissioning step with a radio makes an unlimited token harder to defend.

Constraints:

- Commissioning reuses the existing use case (`commission_device`); the portal
  is a second driving adapter over it (architecture.md).
- No unit is deployed yet, so the firmware may still change what an enrolled unit
  does.
- ADR-0069 reserves `nvs_keys` for later NVS encryption. Nothing in the firmware
  turns flash or NVS encryption on today.

## Decision

### When the setup network exists

1. A unit with **no network settings** opens its setup network on every
   power-up, for **10 minutes**. It never opens on a unit that has settings or is
   enrolled, and never because of an MQTT message or a console command.
2. The only way back to setup is one physical gesture: a button on **GPIO 4**
   (to GND, internal pull-up) held for **10 s** and released. That wipes
   identity, mapping and settings and keeps the command journal. A hold of 30 s
   or more is void. A press counts only after the button was seen released for
   1 s, so a button held at power-up or a stuck pin does nothing.
3. The window closes after saving, after 10 minutes, and after five failed
   sessions. One station may be connected at a time.
4. Changing Wi-Fi on a deployed unit means a reset plus a new token from the
   admin panel. Production builds have no `configure` command, and the portal
   never opens on an enrolled unit.

### Transport and secrets

5. **Security 2** (SRP6a, AES-256-GCM) over ESP-IDF's protocomm, used directly
   rather than through the provisioning manager, on a WPA2 access point with a
   per-device passphrase. Using protocomm directly makes failed sessions
   countable through its security events and keeps the endpoint whitelist ours.
   The setup password and the access-point passphrase are generated per device
   and printed on a label. The device derives the Security 2 salt and verifier
   from the setup password at each start (`esp_srp_gen_salt_verifier`), so that
   password never leaves the unit.
6. Wi-Fi settings and `ol-config` (broker URL, bootstrap login, token) are
   collected, validated and saved **together, or not at all**, through the same
   `commission_device` use case as USB.
7. No response contains a submitted value. The portal exposes no status,
   diagnostics or stored settings.
8. The portal applies the settings itself and then closes. The installer's
   laptop only learns "saved". Success or failure of Wi-Fi and enrollment is read
   in the admin panel. This is deliberate: reporting it would need the portal to
   stay open after the secrets are stored.
9. The broker URL is frozen once a unit is enrolled. On an enrolled unit the
   broker and token fields are ignored and Wi-Fi-only requests are accepted.
10. The portal has no path to the bus port. The layer rule
    `"lc_softap_portal": ["lc_domain"]` enforces it.

### Installer tool

11. For the pilot, installers use a laptop with `scripts/commission.py --softap`.
    The mobile app is a later issue. The tool uses the `esp_prov` of the
    `network_provisioning` copy in the firmware's `managed_components/`, so tool
    and firmware always come from the same build, and reads the Security 2 patch
    level from the unit's `proto-ver` reply (`protocomm_get_sec_version()`). It
    reads secrets without echo, never writes them to disk and never resends after
    a lost connection.

### Backend: 24-hour token

12. A provisioning token is valid for **24 hours** from issue. The backend
    projector sets `provisioning_token_issued_at` from the event's
    `issuedAtIso8601` and clears it when the token is used or the bank is reset.
    `acceptRegistration` refuses a token past 24 hours (expired at exactly
    24 h). A row with a token hash and no issue time counts as expired. A
    migration backfills outstanding tokens with `now()`, so each gets 24 hours
    from the deploy. A projector replay after the deploy gives old unused tokens
    their original time, so they expire; this is intended.
13. The device-facing reply stays success or failure. A distinct "expired" error
    would change the AsyncAPI contract. The backend logs a refused expired token
    with the reason "expired". No audit event is added: that would need a new
    stored event, a presenter entry and translations.
14. The admin token dialog says the token is valid for 24 hours.

### Reset and boot repair

15. Reset writes the empty identity first and, if that fails, wipes nothing. It
    then clears mapping and settings and returns a distinct "partial" result when
    one of those fails; `main` logs an error. The journal is a raw flash
    partition outside the NVS namespace, so a reset keeps it by construction.
16. At boot, an empty identity **with settings present** is not a state any
    normal path creates (`configure` on a fresh unit requires a token). The boot
    logic finishes the wipe (settings and mapping), then decides the mode. The
    same rule repairs a failed rollback in `commission_apply`. The rule looks at
    settings only: an empty identity with a mapping and no settings is a valid
    bench state.
17. Accepted residual: if the settings clear works but the mapping clear fails,
    the unit keeps a stale mapping that cannot be told from a bench mapping. The
    backend marks a reset bank's configuration dirty and sends a fresh
    `apply_config` after enrollment, which overwrites it. The partial result is
    logged so the failure is visible.

### Credential storage

18. Development and bench builds store the Wi-Fi password, bootstrap login,
    token and issued MQTT password unencrypted in flash. Production builds must
    enable flash encryption and NVS encryption before a unit is left unattended.
    The layout already supports this (ADR-0069 reserves `nvs_keys`). Enabling it
    is not part of this change, and **no production unit may ship before it is
    done.** This is a release blocker.

### Open points that the bench spike must settle

The decisions above assume facts that need a board to confirm. The results go
into this ADR before it is accepted; where one fails, the plan says what to do.

The spike ran on 2026-10-21 with ESP-IDF 6.1, network_provisioning 3.0.0 and
an ESP32 rev 3.1 with 4 MB flash, on a bench (development) build. Five of the
six points hold; the button is still open.

| Question | If it fails | Result |
| --- | --- | --- |
| Does `esp_prov` talk to our protocomm endpoints and carry the custom `ol-config` payload? | Change the transport | **Holds.** `commission.py --softap` with the component's `esp_prov` ran `proto-ver`, the Security 2 session (patch level 1), `ol-config` and `prov-config` against an erased unit. The unit saved, joined the Wi-Fi, enrolled with the token, applied its config and opened a compartment from the admin panel |
| Is `ol-config` encrypted by the session (packet capture)? | Stop and redo this ADR; do not build on it | **Holds.** Every TCP byte the tool sent and received was recorded on the client, with made-up values. None of the seven values appears, as UTF-8 or UTF-16. `ol-config` is 90 bytes for a 74-byte payload (a 16-byte tag) and does not start with the payload's version and tag bytes; its reply is 17 bytes. Only `proto-ver` is in clear. The capture was taken above WPA2, which adds a second layer on the air |
| Free heap and largest allocation with the portal running, in AP+STA and AP-only? | The portal applies credentials itself, so AP-only may be enough | **Enough in both; AP-only stays.** Portal open and idle: 108,932 bytes free in AP-only, 107,228 in AP+STA. Lowest over a full session: 99,268 and 97,600. Largest free block 98,304 throughout. Sampled every 2 s, so the last seconds before the restart are not covered |
| Real sizes of `owner_event_t` and the queue on the chip? | Lower the queue depth or queue pointers; the result sets the `_Static_assert` budget | **Holds.** With the ESP32 toolchain `owner_event_t` is 688 bytes (678 of them the setup request), as on the host. 32 deep is 22,016 bytes plus 84 for the queue control block: 22,100, under the 24 KiB budget, which stays |
| ESP-IDF 6.1 on the lwIP DHCP-server advisory? | Pin the fixed patch release and use that image for target builds (ADR-0069) | **Fixed in 6.1.** The advisory (GHSA-g764-gwc3-75m5, CVE-2026-45160) lists v5.2.7 to v6.0.1 as affected, and 6.1's `parse_options()` has every bounds check of the fix. No further pin |
| Does a jumper from GPIO 4 to GND held for 10 s read as a press? | Check wiring and pin choice | **Not run yet** |

## Alternatives Considered

- **Open (unencrypted) SoftAP with an HTTP form.** Rejected: secrets would cross
  the air in clear.
- **Captive portal with a custom web UI.** Rejected: more code on the device,
  no authenticated key exchange, and the mobile app does not exist yet.
- **BLE provisioning.** Rejected for the pilot: an extra radio stack and the same
  installer-tool problem, with no advantage for a fixed locker.
- **Opening the portal from MQTT or the console, or on every failed Wi-Fi
  connection.** Rejected: it would make the setup network reachable on deployed
  units.
- **A wipe-pending marker for the reset.** Rejected: the empty identity written
  first already is the marker, and the boot repair finishes the wipe.
- **Reporting Wi-Fi and enrollment results to the laptop.** Rejected (decision
  8).
- **An "expired" error code to the device.** Rejected: contract change for no
  device-side benefit.

## Consequences

- A unit that was commissioned with wrong Wi-Fi settings keeps retrying and
  cannot be fixed without the button and a reset. The same unused token still
  enrolls it within 24 hours of issue; after that it needs a new one.
- A unit sends its token once. If the broker drops the registration (seen on the
  bench when the broker's auth check timed out), the unit only listens for a late
  reply and needs a reset; the token was not spent, so it enrolls the unit again
  within 24 hours of issue.
- An unused token that was issued before the deploy works for a further 24 hours
  after it. Bounded; mention it in the release notes.
- The backend change is backward compatible with old units, so the backend and
  firmware can merge together.
- Production builds lose `configure`, `unlock`, `command` and `reset-network` on
  the USB console (`LC_BENCH_CONSOLE` keeps them for bench builds).
- Plaintext credentials in flash limit the firmware to an attended bench until
  flash and NVS encryption are enabled (decision 18).

## Supersedes / Superseded By

- Supersedes: ADR-0069 point 10 (plaintext credentials). Decision 18 keeps them
  for bench builds and makes flash and NVS encryption a release blocker.
- Superseded by: none

## References

- Issue #57; PR #321 (firmware), PR #331 (this change)
- [ADR-0069](0069-esp32-locker-firmware.md), [ADR-0070](0070-esp32-command-journal-record-format-and-retention.md)
- [Flow diagrams](../softap-provisioning-flows.md)
- [Research note](../research/softap-provisioning.md)
- [GHSA-g764-gwc3-75m5](https://github.com/espressif/esp-idf/security/advisories/GHSA-g764-gwc3-75m5),
  the lwIP DHCP-server advisory
- `locker-firmware/docs/architecture.md`; tests in `locker-firmware/docs/testing.md`
  (slice S6, hardware group F) and the backend's `RegistrationHandlerTest` and
  `LockerBankProvisioningResetTest`
