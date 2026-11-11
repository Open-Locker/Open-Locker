# ADR-0078: Final ESP32 flash layout from the first firmware

## Status

Accepted

## Date

2026-10-21

## Context

ESP-IDF cannot replace the partition table over the air. Once units are
deployed, changing the flash layout means a USB reflash of every unit. No unit
is deployed, so the layout is still free. OTA (#59), the command journal
([ADR-0070](0070-esp32-command-journal-format.md)) and later NVS encryption all
need room in it. The bench board has 4 MB of flash (confirmed 2026-10-21); production boards
are not chosen yet.

## Decision

We use one layout from the first firmware on, in `partitions.csv` with
`CONFIG_PARTITION_TABLE_OFFSET=0xC000`:

| Name | Type, subtype | Offset | Size |
| --- | --- | --- | --- |
| `nvs` | data, nvs | `0xD000` | `0x6000` |
| `otadata` | data, ota | `0x13000` | `0x2000` |
| `phy_init` | data, phy | `0x15000` | `0x1000` |
| `nvs_keys` | data, nvs_keys (encrypted) | `0x16000` | `0x1000` |
| `ota_0` | app, ota_0 | `0x20000` | `0x1D0000` |
| `ota_1` | app, ota_1 | `0x1F0000` | `0x1D0000` |
| `journal` | data, `0x40` | `0x3C0000` | `0x40000` |

That is 4 MB of flash, two equal OTA slots and no `factory` slot, a reserved
`nvs_keys` partition, the journal at the end of flash, and the partition table
moved to `0xC000` so the bootloader has 44 KB.

## Rationale

The first firmware's hardware tests run on the layout that ships. Two OTA slots
without a factory slot use the app space fully, and rollback never uses a
factory image. The moved table gives the bootloader room for rollback, signature
checks and secure boot: it has about 2.3 KiB of headroom below the default
`0x8000`.

## Alternatives Considered

### Alternative A: Keep a `factory` slot and add smaller OTA slots

- Pros: a "last resort" image.
- Cons: slots of about 1.1 MB, 9 % above the image at the time; the bootloader
  keeps 2.3 KiB of headroom; rollback never uses the factory image.
- Why not chosen: no unit is deployed, so keeping offsets buys nothing.

## Consequences

### Positive

- OTA, flash encryption and NVS encryption later need no new flash layout.

### Negative

- Slot size is a hard limit. With OTA linked the image is `0x120000` bytes of a
  `0x1D0000` slot and the bootloader 26,288 bytes. A signature adds a 4 KiB
  block.

### Risks

- If a board has less than 4 MB of flash the layout does not fit.
- Whether the `encrypted` flag of `nvs_keys` behaves as expected is verified
  only when encryption is turned on.

## Rollout / Migration

Bench boards are reflashed over USB. Changing the layout on deployed units means
a USB reflash of every unit and a new ADR.

## Confirmation

- `scripts/verify.py` fails the build when the app image plus a 4 KiB signature
  block outgrows the smaller OTA slot, or the bootloader outgrows its space
  (`scripts/test_verify.py`).
- `esptool.py flash_id` on every board.
- HW-H1: a blank board flashed with the signed image enrolls and reruns the
  basic checks on this layout.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#321
- Related issues: #56, #59
- ESP-IDF v6.1: [Partition
  Tables](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-guides/partition-tables.html),
  [Over The Air
  Updates](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/system/ota.html),
  [NVS
  Encryption](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/storage/nvs_encryption.html)
