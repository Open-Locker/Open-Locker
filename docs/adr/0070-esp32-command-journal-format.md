# ADR-0070: ESP32 command journal on-flash format

## Status

Implemented (2026-11-13, #321).

## Date

2026-10-16

## Context

The Raspberry Pi client keeps its deduplication state in JSON files (ADR-0046).
That state does one safety job: a command whose outcome is known or uncertain
must never be executed a second time, because the second execution is a second
physical unlock. ADR-0040 and ADR-0041 define the behaviour around it: a repeated
transaction replays the stored response, and a command interrupted by a restart
is answered with an unknown outcome.

The ESP32 has no filesystem and no atomic rename. Its flash is written in small
units, a power loss can cut a write at any byte, it can only clear bits until a
whole 4 KiB sector is erased (about 100,000 cycles, per Espressif), and it reads
erased bytes as `0xFF`.

The journal has to give the application three guarantees:

1. The claim of a command is durable before the unlock is sent.
2. The result is durable before it is published.
3. Delivery is recorded only after the broker has acknowledged the response.

A corrupt journal must stop all actuation, because the firmware can no longer
know which commands it has run. An ordinary power cut must not, or a unit would
stop working until an operator erases the partition.

## Decision

We will keep the journal as an append-only log of checked records in its own
flash partition (`journal`, 256 KiB, [ADR-0078](0078-esp32-flash-layout.md)),
split into two regions of 128 KiB. One region is active; the other is spare.

- **A region is valid only once complete.** Its header, with a generation number,
  is written last. Of two valid headers the higher generation wins.
- **A record exists only when its commit marker is present.** A record is two
  writes: everything up to its checksums, then the marker on its own.
- **A torn tail is not corruption.** Only the record that reaches the last
  programmed byte of the region may be torn, and a torn record is ignored. Any
  other damage, and an intact record that makes no sense, marks the journal
  unhealthy.
- **A claim is never dropped for its marker alone.** A torn last record that is a
  claim and whose body passes its checksum is kept as an open claim. The next
  compaction copies it with a marker.
- **An unhealthy journal stops all actuation.** It answers every command with
  `UNKNOWN_ERROR`, never calls the bus, and repairs itself in no way. Recovery is
  an operator erasing the partition and commissioning again. A reset keeps the
  journal.
- **The partition stays unencrypted** when flash encryption is turned on.
  Encrypted writes go in 16-byte blocks, which records of any length do not fit,
  and erased bytes would no longer read as `0xFF`. The journal holds no secrets.

The byte layout, the reading rules and the steps of moving between regions are in
`website/src/content/docs/dokumentation/esp32-internals.md`. What is kept, and
when compaction runs, is [ADR-0081](0081-esp32-command-journal-retention.md).

## Rationale

Ignoring a torn record is safe because of the write order: nothing acts on a
record before its marker write has returned. A torn claim means no unlock was
sent. A torn completion leaves an interrupted command, answered with an unknown
outcome (ADR-0041). A torn delivered record means the response is published
again, and the backend deduplicates by transaction ID.

Every single-bit corruption of a committed record is detected, with one
exception: the commit marker of the last record itself, which looks exactly like
a partly programmed one, so no marker value can tell them apart. Dropping such a
claim could run its command again when it is delivered again, so a claim with an
intact body is kept instead. That errs towards safety: if the write was really
cut before the marker, the unlock was never sent, and the command is answered
with an unknown outcome (ADR-0041) instead of running. A dropped completion is
answered with an unknown outcome, and a dropped delivered record only sends the
response again, so both stay torn.

## Alternatives Considered

### Alternative A: NVS key-value storage

- Pros: ships with ESP-IDF, handles wear levelling and power loss itself.
- Cons: no multi-record atomicity (a claim and its message ID are two keys),
  garbage collection moves entries at times the journal does not control, and a
  full store has no defined "refuse but keep everything" mode.
- Why not chosen: the journal must decide what may be forgotten, and must be cut
  at every byte in a host test. NVS stays in use for small settings and
  credentials.

### Alternative B: A filesystem (LittleFS or SPIFFS) on the partition

- Pros: familiar files, built-in wear levelling.
- Cons: a large dependency whose power-loss behaviour is not covered by our tests;
  no cheap way to run it on a host in the test gate.
- Why not chosen: the journal borrows littlefs's metadata-pair idea without the
  rest of a filesystem.

### Alternative C: A sector ring with live records copied forward

- Pros: uses more of the partition; the established scheme of Zephyr NVS and ZMS
  and of ESP-IDF NVS pages.
- Cons: a transaction spans several records that may sit in different sectors, so
  deciding what is live involves many more states under power loss than two
  regions with one header each.
- Why not chosen: the kept set (at most 64 KiB) fits half the partition, and two
  regions keep the cut-every-byte tests small.

### Alternative D: RAM only, with the Pi client's retention

- Pros: trivial.
- Cons: a restart forgets every claim, so a command interrupted by a power loss
  would be executed again.
- Why not chosen: it breaks guarantee 1 outright.

## Consequences

### Positive

- The three durability guarantees hold by construction and are tested by cutting
  every write and every erase at every byte.
- An ordinary power cut never stops actuation.
- The code is pure C and runs on a host unchanged.

### Negative

- A replay and every duplicate check cost a flash read, because the RAM index holds
  only hashes and offsets.
- Every start prepares the spare before recovery runs. A used spare costs 32 sector
  erases, about 1.5 s typically and up to 16 s.

### Risks

- Real flash may not fail like the fake: an unordered write, or an erase that
  reports success and leaves bits set. The host tests cut writes both in order and
  out of order, and a blank check catches an erase that leaves bits set, except one
  cut so late that it reads blank but holds weak bits. Those surface as a record
  that fails its check, which stops the journal, so the failure is loud.
- A sector erase can take up to about 500 ms. One sector per idle tick keeps the
  owner task well inside the 15 s task watchdog.

## Rollout / Migration

No unit is deployed, so no journal migrates; a bench board's journal is erased when
the format changes. A device with a journal of an unknown version reports it
unhealthy and does not actuate; a format change across OTA follows the
trial boot and rollback rules that come with OTA (#59). There is no migration from the Pi
client's files, because the two never share a bank.

## Confirmation

- The three guarantees: S2-H27, S2-H28 and S2-H29.
- Records survive reopening, unchanged: S2-H20, S2-H26.
- Torn writes: S2-H21, S2-H22, S2-H59; `test_a_write_cut_out_of_order_is_torn_not_corrupt`
  in `test_journal_edge`.
- Corruption and the unhealthy state: S2-H23, S2-H34, S2-H60.
- Moving regions: `test_a_cut_anywhere_in_a_compaction_loses_nothing_that_is_needed`,
  `test_with_two_valid_headers_the_newer_generation_wins` and
  `test_a_spare_that_does_not_erase_marks_the_journal_unhealthy` in
  `test_journal_retention`.
- Real flash: HW-B4 and HW-B5 (power cuts).

## Supersedes / Superseded By

- Supersedes: none. Follows ADR-0046 for the ESP32 firmware.
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#321
- Related issues: #56, #58
- Related docs: `website/src/content/docs/dokumentation/esp32-internals.md`,
  ADR-0040, ADR-0041, ADR-0046
- Code: `locker-firmware/components/lc_journal/journal.c`
- Torn tail: [LevelDB log reader](https://github.com/google/leveldb/blob/main/db/log_reader.cc),
  [SQLite WAL format](https://www.sqlite.org/walformat.html),
  [RocksDB WAL recovery modes](https://github.com/facebook/rocksdb/wiki/WAL-Recovery-Modes),
  [PostgreSQL xlogrecovery.c](https://github.com/postgres/postgres/blob/master/src/backend/access/transam/xlogrecovery.c)
- Two regions: [littlefs DESIGN.md](https://github.com/littlefs-project/littlefs/blob/master/DESIGN.md)
  and [SPEC.md](https://github.com/littlefs-project/littlefs/blob/master/SPEC.md),
  [ESP-IDF v6.1 NVS](https://docs.espressif.com/projects/esp-idf/en/v6.1/esp32/api-reference/storage/nvs_flash.html),
  [Zephyr NVS](https://docs.zephyrproject.org/latest/services/storage/nvs/nvs.html),
  [Zephyr ZMS](https://docs.zephyrproject.org/latest/services/storage/zms/zms.html),
  [Espressif flash endurance FAQ](https://docs.espressif.com/projects/esp-faq/en/latest/software-framework/storage/nvs.html)
- Erase timing and watchdog: [ESP-IDF v6.1 spi_flash_chip_generic.c](https://github.com/espressif/esp-idf/blob/release/v6.1/components/spi_flash/spi_flash_chip_generic.c),
  [ESP-IDF v6.1 esp_system Kconfig](https://github.com/espressif/esp-idf/blob/release/v6.1/components/esp_system/Kconfig)
