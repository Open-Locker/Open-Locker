# ADR-0088: ESP32 setup secrets in their own partition

## Status

Implemented (2026-11-13, #331).

## Date

2026-11-13

## Context

Every unit carries its setup secrets: the setup Wi-Fi name, its passphrase and
the setup password ([ADR-0083](0083-esp32-setup-session-security-2.md)). They
are written once when the unit is made and printed on its label. Under
[ADR-0078](0078-esp32-flash-layout.md) they sit in the `nvs` partition, next to
the identity, the mapping and the Wi-Fi settings.

At start the firmware erases the whole `nvs` partition when NVS reports
`ESP_ERR_NVS_NO_FREE_PAGES` (full or damaged) or
`ESP_ERR_NVS_NEW_VERSION_FOUND` (written by a newer NVS format, after a
downgrade). The secrets go with it. A unit without them never opens its setup
network, so it can only be commissioned again after someone opens it and writes
the secrets over USB.

ADR-0078 leaves 36 KB unused between `0x17000` and `0x20000`. No unit is
deployed, so the layout can still change without a reflash in the field.

## Decision

The setup secrets get their own NVS partition, `lc_setup`, of `0x3000` bytes at
`0x17000`, in the space ADR-0078 left unused. Nothing else moves.

- The firmware never erases `lc_setup`. When it cannot be read, the setup
  network stays closed, as it does today without secrets.
- The recovery erase still clears `nvs`, so a unit that hits it loses its
  enrollment and opens its setup network on the next start.
- The secrets image is made for `0x3000` bytes and written at `0x17000`.

## Rationale

The secrets never change after the unit is made, so they do not belong in a
partition the firmware may wipe. Keeping them apart turns an NVS erase from
"open the unit and re-flash it" into "commission it again with a new token".

## Alternatives Considered

### Alternative A: Keep the secrets in `nvs` and document a re-flash

- Pros: no layout change.
- Cons: every NVS erase needs someone at the unit with a laptop and USB.
- Why not chosen: the free space exists and no unit is deployed.

### Alternative B: Stop erasing `nvs` on `NEW_VERSION_FOUND`

- Pros: a downgrade fails loudly instead of wiping.
- Cons: a full or damaged `nvs` still erases the secrets.
- Why not chosen: it covers only one of the two causes.

## Consequences

### Positive

- An NVS erase leaves a unit that can be commissioned again over its setup
  network.

### Negative

- The unused space shrinks from 36 KB to 24 KB.
- `lc_setup` is a second NVS partition to cover when NVS encryption comes
  ([#387](https://github.com/Open-Locker/Open-Locker/issues/387)).

### Risks

- A unit flashed with the secrets at the old `0xD000` offset finds none. Bench
  units are re-flashed with the secrets at `0x17000`.

## Rollout / Migration

No unit is deployed. Bench units get the new partition table with a full flash
and their secrets written again at `0x17000`.

## Confirmation

- The target build places `lc_setup` at `0x17000` with `0x3000` bytes.
- HW-F1: a unit flashed with secrets at `0x17000` opens its setup network.

## Supersedes / Superseded By

- Supersedes: part of [ADR-0078](0078-esp32-flash-layout.md): the `nvs`
  partition no longer holds the setup secrets, and `0x17000`–`0x1A000` is used.
- Superseded by: none

## References

- [ESP-IDF v6.1 NVS: `nvs_flash_init_partition`](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/storage/nvs_flash.html)
- [ADR-0078](0078-esp32-flash-layout.md), [ADR-0083](0083-esp32-setup-session-security-2.md)
