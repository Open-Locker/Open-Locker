# ADR-0083: ESP32 setup session uses Security 2 on a WPA2 access point

## Status

Implemented (2026-11-13, #331).

## Date

2026-10-19

## Context

The setup network of [ADR-0074](0074-esp32-softap-provisioning.md) carries secrets:
the Wi-Fi password, bootstrap login and provisioning token. Anyone in radio range
can see the network.

## Decision

We will protect the setup session with **Security 2** (SRP6a, AES-256-GCM) over
ESP-IDF's protocomm, on a WPA2 access point with a per-device passphrase.

- Protocomm is used directly rather than through the provisioning manager, which
  makes failed sessions countable through its security events and keeps the endpoint
  whitelist ours.
- The setup password and the access-point passphrase are generated per device,
  printed on a label and stored in the unit's `lc_setup` NVS namespace. The device
  derives the Security 2 salt and verifier from the setup password at each start, so
  that password is never sent.
- Wi-Fi settings and `ol-config` (broker URL, bootstrap login, token) are collected,
  validated and saved **together, or not at all**.
- No response contains a submitted value. The portal exposes no status, diagnostics
  or stored settings, and the installer only learns "saved".
- The broker URL is frozen once a unit is enrolled. The setup network never runs on an
  enrolled unit, so this applies only to USB commissioning in bench builds: an enrolled
  unit ignores the broker and token fields there and takes the Wi-Fi only.

## Rationale

Security 2 authenticates both sides without sending the password, so a captured
session cannot be replayed. It protects the payload, not the existence of the network,
so the WPA2 passphrase keeps casual joiners out. Reporting the Wi-Fi and enrollment
result to the installer would need the portal to stay open after the secrets are
stored.

The bench spike of 2026-10-21 confirmed that `esp_prov` carries `ol-config` over our
endpoints and that the session encrypts it. The results are in
`website/src/content/docs/dokumentation/esp32-internals.mdx`.

## Alternatives Considered

### Alternative A: Open SoftAP with an HTTP form

- Pros: any browser works.
- Cons: secrets would cross the air in clear.
- Why not chosen: unacceptable for the inputs it carries.

### Alternative B: Captive portal with a custom web UI

- Pros: no tool to install.
- Cons: more code on the device and no authenticated key exchange.
- Why not chosen: the mobile app does not exist yet, and the laptop tool is enough for
  the pilot.

### Alternative C: Security 0 or 1

- Pros: simpler.
- Cons: no authentication (0) or a weaker proof of possession (1).
- Why not chosen: the session carries the token.

## Consequences

### Positive

- A captured session gives away no secret.

### Negative

- Installers need `scripts/commission.py --softap` and the unit's label; the mobile app
  is a later issue (#341).
- The installer reads success or failure of Wi-Fi and enrollment in the admin panel, not
  in the tool.

### Risks

- The setup secrets sit unencrypted in NVS until flash and NVS encryption are on (see the
  Risks of [ADR-0074](0074-esp32-softap-provisioning.md) and
  [#387](https://github.com/Open-Locker/Open-Locker/issues/387)).

## Rollout / Migration

A unit gets its setup secrets as an NVS image made from `scripts/device_secrets.py`.

## Confirmation

- The session: S6-H03.
- Payload handling: S6-H01, S6-H02, S6-H04, S6-H09.
- An enrolled unit: S6-H10, S6-H11.
- On a real unit: HW-F1 and HW-F7 (a traffic capture shows neither the Wi-Fi password nor
  the token in clear).

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#331
- Related issues: #57, #341
- Related docs: `website/src/content/docs/dokumentation/esp32-internals.mdx`
- [GHSA-g764-gwc3-75m5](https://github.com/espressif/esp-idf/security/advisories/GHSA-g764-gwc3-75m5),
  the lwIP DHCP-server advisory
