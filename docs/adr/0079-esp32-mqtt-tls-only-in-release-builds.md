# ADR-0079: ESP32 MQTT uses verified TLS only in release builds

## Status

Accepted

## Date

2026-10-21

## Context

The firmware connects to the public broker, which terminates TLS at Traefik
(ADR-0053). Bench work runs against a local Mosquitto with no certificate. A
unit in the field must not be able to fall back to clear text, whoever has
access to its USB console or setup network, or through an over-the-air update.
This does not cover someone who flashes another image over USB.

## Decision

A release binary accepts only `mqtts://`, always verifies the certificate and
host name against the CA bundle built into the firmware, and has no path to a
plaintext connection.

- Plain `mqtt://` exists only behind the build-time option
  `CONFIG_LC_ALLOW_PLAINTEXT_MQTT` (default off), set by `sdkconfig.dev`. With
  the option on, the log warns on every connect.
- The transport also refuses an `mqtt://` URI when the option is off, which
  covers a URL stored in NVS by a development build before a release was flashed
  over it.
- `ESP_TLS_INSECURE` and `ESP_TLS_SKIP_SERVER_CERT_VERIFY` are a build error in
  every configuration.

## Rationale

A build-time switch cannot be flipped by anyone at the USB console or the setup
portal; a runtime flag could.

## Alternatives Considered

### Alternative A: Plain MQTT as a runtime flag

- Pros: one binary for every environment.
- Cons: anyone with access to the USB console or the setup portal could
  downgrade a deployed unit to clear text.
- Why not chosen: release security would depend on a stored value.

### Alternative B: TLS everywhere with a development CA, or `mqtts://` without verification

- Pros: development exercises the TLS path.
- Cons: a development CA needs certificates for every developer's broker; an
  unverified TLS peer looks secure and is easy to leak into a release.
- Why not chosen: too much setup for bench work, or worse than an honest plain
  connection. A development CA stays the right choice for integration tests that
  must cover TLS.

## Consequences

### Positive

- Release builds cannot connect without verified TLS, checked in CI.

### Negative

- Two build configurations; switching needs a deleted `sdkconfig`.
- A broker on a private CA needs that CA added to the build.

### Risks

- A development build flashed onto a deployed unit allows plain MQTT. Signed
  images (planned with OTA, #59) would stop such a build arriving over the air;
  only secure boot stops it over USB, and that needs physical access to the
  controller. Whether to turn secure boot on is decided before the pilot units are
  converted over USB, so each unit is visited only once
  ([#388](https://github.com/Open-Locker/Open-Locker/issues/388)). It is not a blocker for the pilot.

## Rollout / Migration

None. No unit is deployed.

## Confirmation

- The `locker-firmware-ci` step that fails a default build containing
  `CONFIG_LC_ALLOW_PLAINTEXT_MQTT=y`.
- `test_a_release_build_refuses_a_plain_mqtt_broker` in
  `tests/idf_host/main/test_mqtt_transport.c`.
- S5-H02 and S5-H19 (the broker URL at commissioning).
- HW-C2: a broker with a certificate for another host name and a self-signed one
  are both refused.
- Bench check, still to be done: one `mqtts://` handshake against the real
  broker.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#321
- Related issues: #56, #58
- Related docs: ADR-0053, `website/src/content/docs/dokumentation/esp32-internals.md`
