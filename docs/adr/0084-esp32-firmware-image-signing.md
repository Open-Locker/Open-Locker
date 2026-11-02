# ADR-0084: ESP32 firmware images are signed and checked on update

## Status

Accepted

## Date

2026-10-22

## Context

A unit must not install an image that did not come from us, even if the file
host or a download link is compromised. The bootloader and signing settings
cannot be changed over the air: a unit flashed without them needs a USB visit.
No unit is deployed, so they can still be chosen freely.

Facts checked against ESP-IDF v6.1 sources and documentation:

- `CONFIG_SECURE_SIGNED_APPS_NO_SECURE_BOOT` uses the Secure Boot v2 signature
  scheme without enabling hardware secure boot.
  `CONFIG_SECURE_SIGNED_ON_UPDATE_NO_SECURE_BOOT` defaults to `y` with it and
  makes the OTA APIs check the signature before an image counts as valid.
- `CONFIG_SECURE_SIGNED_ON_BOOT_NO_SECURE_BOOT` depends on the ECDSA scheme.
  With RSA it does not exist, so the bootloader never checks signatures at boot.
- A new image is verified with the public key in the signature block of the
  running app, and on a classic ESP32 revision v3.0 or later only one signature
  block can be appended.
- RSA-3072 signing needs `CONFIG_ESP32_REV_MIN_3`. The bench board is revision
  3.1.

## Decision

Images are signed with **RSA-3072 in the Secure Boot v2 format** and checked by
the running app on update, with hardware secure boot **off**. Settings:
`CONFIG_ESP32_REV_MIN_3=y`, `CONFIG_SECURE_SIGNED_APPS_NO_SECURE_BOOT=y`,
`CONFIG_SECURE_SIGNED_APPS_RSA_SCHEME=y`,
`CONFIG_SECURE_SIGNED_ON_UPDATE_NO_SECURE_BOOT=y`.

- **Every image that can ever be updated from carries a signature block**,
  including the one flashed over USB, because the running app's block supplies
  the key.
- **Signing happens outside the build**, with `scripts/sign_firmware.py`. The
  build produces an unsigned image.
- **The release key** is generated offline or in an HSM, never stored as a plain
  CI secret, with one encrypted offline backup. The custody procedure is still
  to be written (see Risks).
- **A bench key** signs bench images, never the release key. Each bench makes
  its own, kept outside the repository, so bench units never accept a release
  image, and the reverse.
- **No eFuse anti-rollback.** It is irreversible per board and adds nothing
  while secure boot is off.

## Rationale

Signature checks on update protect against remote attackers now. HTTPS plus
signing means a compromised file host or a stolen download link cannot install
code. RSA rather than ECDSA because the ECDSA option uses the older v1 format;
with RSA, turning secure boot on later is an eFuse step, not a new signing
pipeline.

## Alternatives Considered

### Alternative A: Hardware secure boot v2 and flash encryption now

- Pros: protects against physical flashing too.
- Cons: irreversible eFuses, a key that cannot be revoked on a classic ESP32, no
  spare boards to waste before the pilot.
- Why not chosen: the signing format already supports it; the step comes with
  flash encryption, a release blocker
  ([ADR-0074](0074-esp32-softap-provisioning.md)).

### Alternative B: ECDSA signatures

- Pros: smaller signatures.
- Cons: ESP-IDF's ECDSA option uses the v1 format; moving to secure boot later
  means a new signing pipeline.
- Why not chosen: see the rationale.

### Alternative C: A custom verifier with a rotatable key in the app

- Pros: key rotation over the air.
- Cons: security-critical code of our own, and the bootloader would still not
  check it.
- Why not chosen: a leaked key is a USB visit; revisit if units are deployed at
  a scale where that hurts.

## Consequences

### Positive

- A compromised file host or stolen link cannot install code.

### Negative

- **The key cannot be rotated over the air.** Only one signature block is
  possible and the next image is verified against the running app's block, so a
  new public key shipped inside an image changes nothing. A leaked or lost key
  means a USB visit to every unit. We accept this for the pilot. Once hardware
  secure boot is on, the chip holds one key that cannot be revoked either.
- An unsigned running image makes every later update fail with
  `UPDATE_VERIFY_FAILED`.
- The firmware needs chip revision 3.0 or later.
- The backend cannot sign for operators; they upload signed images.

### Risks

- The bootloader does not check signatures at boot, so someone with physical
  access can still flash any image. The signature protects the network path, not
  the device against its holder.
- **Open: release key custody.** Who holds the key and its offline backup, and
  whether an HSM is used. Needs an owner before the first release.
- **Open: bench key.** Who keeps a shared bench key for a team.
- **Open: secure boot and flash encryption timing** (irreversible).

## Rollout / Migration

The first image of any bench or pilot unit is flashed over USB, signed.

## Confirmation

- `scripts/test_sign_firmware.py`.
- HW-H1: a blank board flashed with the signed image enrolls and passes the
  basic checks.
- HW-H4: an image with a bad signature, and one signed with another key, are
  refused. This is the only check of the signature path on the chip.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#336
- Related issues: #59
- Related docs: [ADR-0075](0075-esp32-ota-firmware-updates.md)
- ESP-IDF v6.1: [Secure Boot
  v2](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/security/secure-boot-v2.html),
  [Kconfig
  reference](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/kconfig-reference.html)
