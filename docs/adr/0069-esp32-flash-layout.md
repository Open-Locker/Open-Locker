# ADR-0069: ESP32 flash layout with two OTA slots

## Status

Proposed

## Date

2026-10-15

## Context

`locker-firmware/` ran from one `factory` app partition, with the command
journal (ADR-0068) right after it at `0x190000`. OTA (#59) needs two app slots
and an `otadata` partition, and later secure boot, flash encryption and NVS
encryption need room in the bootloader region and an `nvs_keys` partition.

ESP-IDF v5.4 cannot replace the bootloader or the partition table over the air,
so whatever layout a unit is flashed with stays for its life unless someone
reflashes it over USB. No unit is deployed yet, so the layout can still be
chosen freely. Doing it in the first firmware PR means that PR's hardware tests
run against the layout that ships.

Constraints:

- 4 MB flash is assumed (ADR-0005); the board's flash size is not confirmed yet.
- App partitions are 64 KB aligned; `otadata` is 0x2000.
- Today's image is 0xf6090 bytes (about 1 MB).
- The bootloader is 25,952 bytes with about 2.7 KB left below the default
  partition table offset `0x8000`. Rollback, signature checks and secure boot
  make it larger.
- The firmware finds the journal by its label
  (`lc_storage_nvs/partition_flash.c`), so its offset is free.

## Decision

Use this table, with the partition table at `0xC000`
(`CONFIG_PARTITION_TABLE_OFFSET=0xC000`):

```csv
# Name,    Type, SubType,  Offset,   Size,     Flags
nvs,       data, nvs,      0xD000,   0x6000,
otadata,   data, ota,      0x13000,  0x2000,
phy_init,  data, phy,      0x15000,  0x1000,
nvs_keys,  data, nvs_keys, 0x16000,  0x1000,   encrypted
ota_0,     app,  ota_0,    0x20000,  0x1D0000,
ota_1,     app,  ota_1,    0x1F0000, 0x1D0000,
journal,   data, 0x40,     0x3C0000, 0x40000,
```

- Two equal app slots of 0x1D0000 (1.8 MB), about 88 % above today's image.
- No `factory` partition.
- The bootloader gets `0x1000` to `0xC000` (44 KB).
- `nvs_keys` is reserved and unused until flash encryption and NVS encryption
  are turned on.
- The journal moves to the end of flash and is **not** flash-encrypted: its
  byte-granular appends and separate commit-marker writes (ADR-0068) do not fit
  the 16-byte blocks of encrypted writes, and it holds no secrets.
- The table ends exactly at 4 MB.

This ADR fixes the layout only. Rollback, image signing and the minimum chip
revision belong to the OTA ADR (#59).

## Rationale

- With empty `otadata`, the bootloader boots `ota_0`, so `idf.py flash` works as
  before and the first OTA writes `ota_1`.
- A factory slot would cost a third of the app space and hold stale firmware:
  the bootloader never rolls back to it, it only boots it when `otadata` is
  invalid. ESP-IDF's eFuse anti-rollback also requires no factory slot.
- Moving the table to `0xC000` removes the bootloader size limit for good,
  including Secure Boot v2 later, at the cost of moving the table 16 KB further
  in.
- Reserving `nvs_keys` now costs 4 KB from a gap that is unused anyway, and
  avoids a USB reflash of every unit when NVS encryption is turned on.

## Alternatives Considered

### Alternative A: Keep `factory` and add two smaller OTA slots after the journal

- Pros: every existing offset stays; a "last resort" image.
- Cons: slots of 0x110000, about 9 % above today's image; the bootloader stays
  at 2.7 KB of headroom; the factory image is never used by rollback.
- Why not chosen: no unit is deployed, so keeping offsets buys nothing.

### Alternative B: Keep the journal at `0x190000`, with `ota_1` after it

- Pros: ADR-0068 and the README erase command stay as they were.
- Cons: the app slots are split around the journal and cannot grow; still no
  bootloader room without moving NVS.
- Why not chosen: a clean layout costs nothing before deployment.

### Alternative C: Set the layout in the OTA PR

- Pros: this PR stays smaller.
- Cons: every hardware group of PR 1 must run again on the new layout, and
  bench units are reflashed twice.
- Why not chosen: the change is small and the hardware record of PR 1 is not
  written yet.

## Consequences

### Positive

- PR 1's hardware tests verify the layout that ships.
- OTA (#59) adds code and configuration only, no layout change.
- Flash encryption and NVS encryption later need no new layout.

### Negative

- 640 KB less than a single-app layout is left for the app; the 36 KB from
  `0x17000` to `ota_0` at `0x20000` are unused.
- Bench boards flashed before this change must be erased fully and
  commissioned again. A local `sdkconfig` from before keeps the old table
  offset and must be deleted.

### Risks

- If the board has less than 4 MB of flash, the layout does not fit. Confirm
  with `esptool.py flash_id` on every board (blocker in the OTA plan).
- Whether the `encrypted` flag on `nvs_keys` behaves as expected once flash
  encryption is on is verified only then, on a `prod-test` board.

## Rollout / Migration

No unit is deployed. Bench boards: `idf.py -p <port> erase-flash flash`, then
commission again. After this, changing the layout means a USB reflash of every
unit and a new ADR.

## Supersedes / Superseded By

- Supersedes: none. Amends the journal location assumed in ADR-0068.
- Superseded by: none

## References

- Related PRs: #321
- Related issues: #56, #59
- Related docs: `locker-firmware/docs/architecture.md`, ADR-0005, ADR-0068;
  OTA planning in #59
- ESP-IDF v5.4: [Partition Tables](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-guides/partition-tables.html),
  [Over The Air Updates](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/system/ota.html),
  [NVS Encryption](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/storage/nvs_encryption.html)
