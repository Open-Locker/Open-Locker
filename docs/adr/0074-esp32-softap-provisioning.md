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

5. Espressif's provisioning manager with **Security 2** (SRP6a, AES-256-GCM) on
   a WPA2 access point with a per-device passphrase. The setup password and the
   access-point passphrase are generated per device and printed on a label.
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

11. For the pilot, installers use a laptop with `esp_prov`. The mobile app is a
    later issue. The commissioning tool reads secrets without echo, never writes
    them to disk and never resends after a lost connection.

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

| Question | If it fails |
| --- | --- |
| Does a provisioning-manager session carry a custom `ol-config` payload, and can our Python tool send it? | Write the client against the `esp_prov` library, or change the transport |
| Is `ol-config` encrypted by the session (packet capture)? | Stop and redo this ADR; do not build on it |
| Free heap and largest allocation with the portal running, in AP+STA and AP-only? | The portal applies credentials itself, so AP-only may be enough |
| Real sizes of `owner_event_t` and the queue on the chip? | Lower the queue depth or queue pointers; the result sets the `_Static_assert` budget |
| ESP-IDF 6.1 on the lwIP DHCP-server advisory? | Pin the fixed patch release and use that image for target builds (ADR-0075) |
| Does a jumper from GPIO 4 to GND held for 10 s read as a press? | Check wiring and pin choice |

### Implementation status

The decision logic is built and host-tested: boot mode and repair, the reset
gesture, the portal session rules, the `ol-config` parser and collector, and the
production console. The radio adapter (`lc_softap_espidf`), its wiring in
`app_main` and the laptop tool (`commission_softap.py`) are written against the
ESP-IDF 6.1 headers and compile-checked in the `espressif/idf:v6.1` image only (ADR-0075)
(default and production configuration, and the IDF host tests). They have not run
on a board. The adapter uses protocomm directly, not the provisioning
manager, so that failed Security 2 sessions are countable through the protocomm
session events and the endpoint whitelist is ours; whether `esp_prov` talks to
it, and whether the open points above hold, is for the bench spike. The Security
2 salt and verifier are derived on the device from the label's setup password at
each start (`esp_srp_gen_salt_verifier`), which keeps that password on the unit.
The owner queue's memory budget is a host measurement.

There is no bench result on the pinned build (ADR-0075) yet: the open points above
and HW-F1 to HW-F8 are all open.

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
  cannot be fixed without the button, a reset and a new token. The same unused
  token still enrolls it within 24 hours of issue.
- An unused token that was issued before the deploy works for a further 24 hours
  after it. Bounded; mention it in the release notes.
- The backend change is backward compatible with old units, so the backend and
  firmware can merge together.
- Production builds lose `configure`, `unlock`, `command` and `reset-network` on
  the USB console (`LC_BENCH_CONSOLE` keeps them for bench builds).
- Plaintext credentials in flash limit the firmware to an attended bench until
  flash and NVS encryption are enabled (decision 18).

## Planned tests

The host catalogue gets slice S6 (see `locker-firmware/docs/testing.md`; rows
are added with the commit that writes each test): S6-H01 to H26 and hardware
checks HW-F1 to HW-F8. Backend tests extend `RegistrationHandlerTest` and
`LockerBankProvisioningResetTest`: fresh token accepted; token past 24 h
refused; boundary (valid while `now < issued_at + 24 h`); re-issue restarts the
clock; a used token and an enrolled locker are unaffected after 24 h; a token
hash with no issue time counts as expired; a replay of
`LockerProvisioningTokenIssued` sets the time from the event, not `now()`.

## References

- Issue #57; PR #321 (firmware)
- [ADR-0069](0069-esp32-flash-layout.md), [ADR-0070](0070-esp32-command-journal-record-format-and-retention.md)
- [Flow diagrams](../softap-provisioning-flows.md)
- [Research note](../research/softap-provisioning.md)
- `locker-firmware/docs/architecture.md`, `locker-firmware/docs/testing.md`
