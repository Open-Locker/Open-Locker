# ADR-0071: ESP32 plain MQTT only in development builds

## Status

Proposed

## Date

2026-10-20

## Context

`locker-firmware/` accepts only `mqtts://` broker URLs, always verifies the
broker certificate and host name against the built-in CA bundle, and refuses to
build with `ESP_TLS_INSECURE`. That is right for deployed units.

For development and bench tests it gets in the way: the local Docker stack's
Mosquitto listens on plain `mqtt://`, and giving it a certificate the
device trusts means a public name or a custom CA in the bundle. Developers need
to point a board at a local broker without TLS.

A unit commissioned in the field must never be talked into plain MQTT: its
bootstrap credentials, the one-time token and every unlock command would cross
the network in clear text. The USB console and the coming SoftAP portal
(ADR-0074) are open to anyone with physical or radio access to the unit, so the
choice cannot be a runtime setting.

## Decision

Plain MQTT is a build-time option, off by default:

- Kconfig `CONFIG_LC_ALLOW_PLAINTEXT_MQTT` (component `lc_mqtt_espidf`),
  `default n`. `sdkconfig.dev` turns it on; developers build with
  `idf.py -D SDKCONFIG_DEFAULTS="sdkconfig.defaults;sdkconfig.dev" build`.
  A plain `idf.py build` is the release configuration.
- Commissioning (`lc_application/commission_device.c`) accepts `mqtt://` only
  when the composition root calls `lc_commission_allow_plaintext_broker`,
  which `main/` does only under the option. The pure component never reads
  `CONFIG_*`, so both behaviours are host-tested (S5-H02, S5-H19).
- The transport (`lc_mqtt_espidf/transport.c`) refuses an `mqtt://` URI when
  the option is off. This also covers a URL that a development build stored in
  NVS before a release was flashed over it. When the option is on it logs a
  warning on every connect.
- `mqtts://` is verified the same way in every build. `ESP_TLS_INSECURE` and
  `ESP_TLS_SKIP_SERVER_CERT_VERIFY` stay a build error: a development build
  may skip TLS, never accept an unverified TLS peer.
- CI builds the default configuration and fails if
  `CONFIG_LC_ALLOW_PLAINTEXT_MQTT=y` ends up in its `sdkconfig`.

## Rationale

A release binary without the option has no path to a plaintext connection,
whatever is stored on the device or sent over the console. Developers get plain
MQTT with one extra build flag, and the release build needs no flag at all, so
forgetting something fails safe.

## Alternatives Considered

### Alternative A: runtime flag in NVS or in the setup request

- Pros: one binary for every environment.
- Cons: anyone at the USB console or the setup portal could downgrade a
  deployed unit to clear text.
- Why not chosen: the security of the release would depend on a stored value.

### Alternative B: TLS everywhere, with a development CA

- Pros: one code path; development exercises TLS.
- Cons: every developer needs certificates and a broker set up for them; the
  local stack does not provide that today.
- Why not chosen: too much setup for bench work. It remains the better choice
  for integration tests that must cover TLS.

### Alternative C: `mqtts://` without verification in development

- Pros: keeps the TLS code path.
- Cons: accepts any peer while looking secure; easy to leak into a release.
- Why not chosen: an unverified TLS connection is worse than an honest plain one.

## Consequences

### Positive

- Bench work against the local Mosquitto without certificates.
- Release builds keep the current guarantee, now checked in CI.

### Negative

- Two build configurations. Switching needs a deleted `sdkconfig`, or the old
  values stay.

### Risks

- Someone flashes a development build onto a deployed unit. Mitigation: the
  warning in the log, and release images come only from the default build.
  Signed releases (secure boot, #59) will close this fully.

## Rollout / Migration

No unit is deployed. Existing boards keep working; a board commissioned with
`mqtt://` on a development build is refused by a release build and has to be
commissioned again with `mqtts://`.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: #321
- Related issues: #56
- Related docs: `locker-firmware/README.md`, `locker-firmware/docs/architecture.md`,
  ADR-0053 (public MQTT TLS at Traefik), ADR-0074 (SoftAP provisioning)
