# ADR-0070: ESP32 command journal record format and retention

## Status

Proposed

## Date

2026-10-16 (first draft 2026-10-12)

## Context

The Raspberry Pi client keeps its deduplication state in JSON files
(ADR-0046). That state does one safety job: a command whose outcome is known or
uncertain must never be executed a second time, because the second execution is
a second physical unlock. ADR-0040 and ADR-0041 define the behaviour around it:
a repeated transaction replays the stored response, and a command interrupted by
a restart is answered with an unknown outcome.

The ESP32 firmware (`locker-firmware/`, `locker-firmware/docs/architecture.md`)
has no filesystem and no atomic rename. Its flash:

- is written in small units, and a write can be cut short by a power loss at any
  byte, leaving bytes partly programmed;
- can only clear bits until a whole 4 KiB sector is erased, and every erase
  wears the sector (Espressif states about 100,000 cycles);
- reads erased bytes as `0xFF`.

The journal has to give the application three guarantees (the safety rules in
`locker-firmware/docs/architecture.md`):

1. The claim of a command is durable before the unlock is sent.
2. The result is durable before it is published.
3. Delivery is recorded only after the broker has acknowledged the response.

and one rule about failure: a corrupt journal must stop all actuation, because
the firmware can no longer know which commands it has run.

The first draft of this ADR never pruned: after 64 commands for the life of the
device it refused every new command, and any damaged record, including one torn
by an ordinary power cut, stopped actuation until an operator erased the
partition. Both make the firmware unusable outside a bench. This version keeps
the record format and the guarantees and replaces the torn-write handling and
the retention. The sources behind each decision are listed under References.

How late a duplicate can arrive decides what may be forgotten:

- The backend publishes each command once; a queue retry (`--tries=3`) can
  publish the same transaction again under a new message ID shortly after.
- The firmware keeps a persistent MQTT session, so the broker queues commands
  for an offline unit and resends unacknowledged ones on reconnect (MQTT 3.1.1,
  [MQTT-3.1.2-5], [MQTT-4.4.0-1]). The broker configuration sets no
  `persistent_client_expiration`, and Mosquitto's default is to never expire.
- esp-mqtt sends the PUBACK of a command after the event handler returns, and
  the handler only queues the command for the owner task. A crash in between
  loses the command (no actuation, no response, the backend times out); it
  never duplicates it.

So duplicates are recent in command count in practice, but nothing bounds them
in time until commands carry an age the device can check (#134).

## Decision

The journal is a log of records in its own flash partition (`journal`, 256 KiB,
ADR-0069), split into two regions of 128 KiB. One region is active and only
appended to; the other is spare. When the active region fills, the records
still needed are copied into the spare region, which then becomes active. The
journal sits behind a flash I/O struct (`read`, `write`, `erase`) so the same
code runs on a host against a memory fake.

### Regions

Each region starts with a 16-byte header:

| Offset | Size | Content |
| --- | --- | --- |
| 0 | 4 | Magic `OLJR` |
| 4 | 1 | Format version `0x01` |
| 5 | 3 | `0xFF` |
| 8 | 4 | Generation, little endian, 1 to `0xFFFFFFFE` |
| 12 | 4 | CRC-32 (IEEE) of bytes 0 to 11, little endian |

Records follow from offset 16. A region's header is written **last**, after all
its records, so a region is valid only once it is complete.

At start:

- One valid header: that region is active; the other is spare.
- Two valid headers: the higher generation is active (a restart between
  committing a new region and invalidating the old one). Equal generations mark
  the journal unhealthy.
- No valid header, and nothing programmed outside the first 16 bytes of
  region A: an empty journal (never used, or its first header write was cut).
  The first append writes generation 1 to region A, erasing it first if needed.
- No valid header and any record byte programmed: the journal is unhealthy. A
  corrupt header must never be read as "nothing was done".

The generation is a `uint32_t` compared with `>`. Each compaction erases one
region, so the flash wears out after about 200,000 compactions, far below a
wrap.

### Record format, version 1

| Offset | Size | Content |
| --- | --- | --- |
| 0 | 1 | Magic `0xA7` |
| 1 | 1 | Format version `0x01` |
| 2 | 1 | Type: 1 claim, 2 completion, 3 delivered, 4 message |
| 3 | 2 | Payload length, little endian, at most 320 |
| 5 | 1 | Header check: CRC-8 (polynomial 0x07) of bytes 1 to 4 |
| 6 | n | Payload |
| 6 + n | 4 | Record check: CRC-32 (IEEE) of bytes 1 up to the end of the payload, little endian |
| 10 + n | 1 | Commit marker `0xC3` |

The payload is a sequence of length-prefixed texts (one length byte, then the
bytes) and single bytes:

- claim: transaction ID, message ID, action name;
- completion: transaction ID, action name, error code (one byte), applied config
  hash (empty unless an `apply_config` succeeded);
- delivered: transaction ID;
- message: transaction ID, a further message ID for it.

Every text is at most 128 bytes and holds no NUL. The largest record is
331 bytes. The checks do not cover the record's position, so a record can be
copied unchanged into another region.

### Writing

A record is written as two writes: everything up to the check, then the commit
marker on its own. A record exists only when its marker is present. If a write
or an erase fails, the journal marks itself unhealthy in memory and refuses
every further operation until the next start. A call returns true only after
the marker is written.

### Reading and torn writes

At start the active region is scanned from offset 16. Only the record that
reaches the last programmed byte of the region (the *tail*) may be torn:

1. `0xFF` where a record would start ends the log when every byte after it is
   `0xFF`. With programmed bytes after it, it is a damaged header (rule 3 or
   4): a page program cut by a power loss can leave any of its bytes erased,
   the first one included.
2. A record that passes every check and ends in `0xC3` is committed.
3. The tail record is **torn**, not corrupt, when it does not end in a valid
   `0xC3` marker: its marker is erased or partly programmed, or its header is
   damaged and the last programmed byte lies within 331 bytes of its start. A
   torn record is ignored and the journal is healthy.
4. Everything else marks the journal unhealthy: a damaged record followed by
   any programmed byte, a record that ends in `0xC3` but fails a check, an
   unknown version or type, and a record that is intact but makes no sense (a
   claim for a known transaction or message ID, a completion or delivery for a
   transaction that is not in the state to receive it, an error code that does
   not exist).

Ignoring a torn record is safe because of the write order: nothing acts on a
record before its marker write has returned. A torn claim means no unlock was
sent. A torn completion leaves an interrupted command, answered with an
unknown outcome (ADR-0041). A torn delivered record means the response is
published again, and the backend deduplicates by transaction ID.

Torn debris is never overwritten. After a torn tail, the next append first
compacts, which leaves the debris behind in the old region.

Every single-bit corruption of a committed record is detected, with one
exception: the magic, the commit marker and the type are compared exactly, the
header check covers the length, and the record check covers the payload. Since
a committed record ends in `0xC3`, a bit error inside it is not mistaken for a
torn tail. The exception is the commit marker of the last record itself: a
marker that is not `0xC3` there looks exactly like a partly programmed one, so
that record reads as torn and is dropped. A dropped claim can then run again
if its command is delivered again, and a dropped completion is answered with
an unknown outcome. This needs a bit error in that one byte and is accepted.

### Retention

Transactions fall into two kinds:

- **Open**: claimed with no completion (unknown outcome), or completed and not
  yet delivered. An open transaction is never forgotten. At most 32 are kept;
  when that limit is reached, new claims are refused with `UNKNOWN_ERROR` while
  stored responses are still replayed and delivered. The limit clears itself as
  the broker acknowledges the responses.
- **Delivered**: completed and acknowledged by the broker. Only these are
  pruned, oldest first by log order, and only during compaction. The newest
  256 delivered transactions are always kept, as long as they fit the snapshot
  budget below.

A pruned transaction is forgotten completely. A copy of it arriving later is
treated as a new command. This is the accepted residual risk of this ADR: a
duplicate would have to arrive after 256 newer delivered commands, which the
redelivery paths in the context do not produce, but nothing rules out. It
closes when commands carry a timestamp and the device refuses commands older
than a bound *T* (#134): then retention also requires a delivered transaction
to be older than *T* plus a clock margin, measured on SNTP time. Until then,
nothing is pruned by time.

Message IDs are kept with their transaction: the claim's message ID and every
later message ID for the same transaction (at most 512 in total). Compaction
keeps the later ones only for open transactions; a delivered transaction keeps
just the message ID of its claim. Otherwise repeats under new message IDs (a
backend retry publishes one) could leave 512 IDs in the retained set, and every
compaction would end with the index still full, refusing every claim for good.
A copy under a dropped message ID is still caught by its transaction ID and
gets the stored response again, which the backend ignores. When the message
limit is reached, a further message ID is not recorded; the transaction ID
still deduplicates the command.

`apply_config`, and later the OTA update command (#59), are commands like any
other and use transactions.

### Compaction

1. **Spare ready.** The spare region is erased ahead of time, one 4 KiB sector
   per idle tick of the owner task, header sector first. After the last sector
   it is read back; it is *ready* only if every byte reads `0xFF`. This state is
   kept in RAM only. After a restart the spare is read once: if every byte is
   `0xFF` it is ready without an erase, otherwise it is erased as above. If the
   blank check after an erase fails twice, the journal is unhealthy. Skipping
   the erase of a blank spare keeps a boot loop from wearing out the flash; the
   price is that an erase cut so late that it reads blank but holds weak bits is
   not redone. Such bits surface as a record that fails its check, which stops
   the journal, so the failure is loud, not silent.
2. **When.** Compaction runs when the owner task is idle and the active region
   is more than 75 % full and something was appended since the last
   compaction, before an append that would not fit the room reserved for open
   transactions, before a claim when the index is full (288 transactions or
   512 message IDs), and before any append that follows a torn tail. If the
   spare is not ready at that moment, the append is refused: a claim with
   `UNKNOWN_ERROR`, a further message ID by not recording it.
3. **Copy.** First the retained set is chosen: every open transaction, then
   delivered transactions from the newest backwards, until 256 are kept or the
   next one would take the copy past half a region (64 KiB). The records of the
   retained transactions are then copied byte for byte into the spare, after
   its header space, in their original log order, so claim order is kept.
   Further message records are copied only for open transactions, and only in
   the room the budget has left after the claim, completion and delivered
   records, which are always copied. Delivered transactions keep only the
   message ID of their claim. Torn debris and pruned transactions are not
   copied.
4. **Commit.** The spare's header is written with generation + 1. From this
   write on, the new region is active.
5. **Invalidate.** The old region's header sector is erased at once. The rest of
   the old region is erased ahead as the next spare (step 1).

After step 5 the index is rebuilt by scanning the new region.

A power cut before step 4 leaves the old region active and the half-written
spare without a header; it is erased again. A power cut between steps 4 and 5
leaves two valid headers, and the higher generation wins. Step 5 shortens that
window to one erase.

A claim is accepted only if the active region has room for it **and** for the
completion and delivered records of every open transaction, each at its largest
size for its IDs. A message record may use only room that is not reserved this
way.

### Index in RAM

The scan builds an index that holds no ID texts and no responses:

- per transaction: an 8-byte hash (FNV-1a) of the transaction ID, its state,
  the flash offsets of its claim and completion records, the bytes its claim,
  completion and delivered records take in the region, and the lengths of its ID and action name, from which the
  room reserved for finishing it is computed;
- per message ID: an 8-byte hash, the index of its transaction, and the offset
  of the record that holds it.

A hash match is confirmed by reading the claim (or message) record from flash
and comparing the full ID; a collision is just a mismatch. A replay reads the
stored response from the completion record. A failed read marks the journal
unhealthy. For 288 transactions and 512 message IDs the index is about 15 KiB,
allocated statically.

The port lists only open transactions (`count` and `at`), in claim order. That
is all restart recovery and the response flush need, and it bounds the flash
reads of one flush to the 32 open transactions.

### What unhealthy means

An unhealthy journal answers every command with `UNKNOWN_ERROR` and never calls
the bus. It repairs itself in no way. With the torn-tail rule above, it means
damage a power cut cannot cause: a failed flash operation, a bit error, or a
software bug. Recovery is an operator action: erase the partition with
`parttool.py --port <port> --partition-table-offset 0xC000 erase_partition --partition-name journal`
and commission the device again. The journal is the only state that a network
reset (`reset-network`, see the firmware README) keeps.

## Alternatives Considered

### Alternative A: NVS key-value storage

- Pros: ships with ESP-IDF, handles wear levelling and power loss itself.
- Cons: no multi-record atomicity (a claim and its message ID are two keys),
  garbage collection moves entries at times the journal does not control, and a
  full store has no defined "refuse but keep everything" mode.
- Why not chosen: the journal must decide what may be forgotten, and must be
  cut at every byte in a host test. NVS stays in use for small settings and
  credentials.

### Alternative B: A filesystem (LittleFS or SPIFFS) on the partition

- Pros: familiar files, built-in wear levelling.
- Cons: a large dependency whose power-loss behaviour is not covered by our
  tests; no cheap way to run it on a host in the test gate.
- Why not chosen: the journal borrows littlefs's metadata-pair idea without
  the rest of a filesystem.

### Alternative C: A sector ring with live records copied forward

- Pros: uses more of the partition; the established scheme of Zephyr NVS and
  ZMS and of ESP-IDF NVS pages.
- Cons: a transaction spans several records that may sit in different sectors,
  so copying forward and deciding what is still live involves many more states
  under power loss than two regions with one header each.
- Why not chosen: the retained set (at most 64 KiB) fits half the partition,
  and two regions keep the cut-every-byte tests small.

### Alternative D: RAM only, with the Pi client's retention

- Pros: trivial.
- Cons: a restart forgets every claim. A command interrupted by a power loss
  would be executed again.
- Why not chosen: it breaks guarantee 1 outright.

### Alternative E: Never prune (the first draft of this ADR)

- Pros: forgets nothing.
- Cons: the unit refuses all commands after 64, and the only remedy, erasing
  the partition, forgets everything at once.
- Why not chosen: bounded retention with a named residual risk forgets less in
  practice than the operator erase it replaces.

### Alternative F: Retention by age, like the Pi client (30 days)

- Pros: matches ADR-0046.
- Cons: the device has no trusted time before SNTP, and an age limit without a
  matching limit on accepted commands still lets an old queued command arrive
  after its record is gone (RFC 4120, section 3.2.3).
- Why not chosen: it waits for #134, and is then added on top of the count.

## Consequences

### Positive

- The three durability guarantees hold by construction and are tested by cutting
  every write and every erase at every byte.
- An ordinary power cut never stops actuation.
- The journal runs indefinitely without operator action.
- A corrupt record cannot be mistaken for a valid one by a single bit error.
- The index needs about 15 KiB of RAM instead of 43 KiB.
- The code is pure C and runs on a host unchanged.

### Negative

- A duplicate that arrives after 256 newer delivered commands is executed
  again, until #134.
- Up to 32 undelivered responses: while the broker is unreachable for long, new
  commands are refused.
- A replay and every duplicate check cost a flash read.
- Each compaction erases one region, so each sector is erased once every two
  compactions and the flash lasts about 200,000 compactions. With UUID IDs a
  delivered transaction takes about 220 bytes, so 256 of them fit the copy
  budget and the index, not the region, decides when to compact: about every
  30 commands. That gives about six million commands, roughly 1.2 years of
  continuous commands at ten per minute, or far more than a lifetime at a few
  hundred a day. A larger index would compact by bytes instead (about every
  300 commands) at the cost of more RAM. A restart adds an erase only when the
  spare holds records, that is once after each compaction, so restarts add no
  wear of their own: a boot loop costs reads, not erases.
- Right after a compaction the spare takes 33 idle ticks of the owner task
  (32 sector erases and the blank check, at least 3.3 s at one per 100 ms tick)
  to be ready again, while the index is full again after about 30 claims. A
  faster burst has its further claims refused with `UNKNOWN_ERROR` until the
  spare is ready.
- Every start prepares the spare before recovery runs, so that a torn tail can
  be compacted before recovery appends to the journal. A blank spare costs one
  read of the region; a used one 32 sector erases, about 1.5 s typically and up
  to 16 s, and as much again if the start compacts.
- The partition stays unencrypted when flash encryption is turned on: encrypted
  writes go in 16-byte blocks, which records of any length do not fit. The
  journal holds no secrets.

### Risks

- Real flash may not fail like the fake: an unordered write, or an erase that
  reports success and leaves bits set. The host tests cut writes both in order
  and out of order (the last bytes land, the first stay erased); either reads
  as a torn tail by rule 3, unless the last programmed byte happens to be
  `0xC3`, which fails closed. An erase that leaves bits set is caught by the
  blank check. Both are exercised on the device by HW-B4 and HW-B5, and
  compaction by a hardware endurance run (HW-G1).
- A sector erase can take up to about 500 ms (ESP-IDF driver timeouts; no
  typical figure is published). Erasing one sector per idle tick keeps the
  owner task well inside the 5 s task watchdog. At start the erases run in a
  loop that yields to the scheduler after each one.
- Whether the esp-mqtt version bundled with ESP-IDF v5.4 sends the PUBACK after
  the handler, as upstream does, is not verified. Either way it decides only
  whether a crash loses a command, never whether it is executed twice.

## Rollout / Migration

No unit is deployed, and this ADR was never accepted, so version 1 of the
format is redefined rather than versioned. Bench boards need a full erase,
which ADR-0069 already requires. A device with a journal of an unknown version
reports it unhealthy and does not actuate. There is no migration from the Pi
client's files: a Pi client and an ESP32 never share a bank at the same time
(hardware check HW-G2 in `locker-firmware/docs/testing.md`).

## Supersedes / Superseded By

- Supersedes: none. Follows ADR-0046 for the ESP32 firmware.
- Superseded by: none. When #134 lands, the age condition in Retention is
  enabled in a new ADR that amends this one.

## References

- Related PRs: Open-Locker/Open-Locker#321
- Related issues: #56, #58, #59, #134
- Related docs: `locker-firmware/docs/architecture.md`,
  `locker-firmware/docs/testing.md`, ADR-0040, ADR-0041,
  ADR-0046, ADR-0069
- Code: `locker-firmware/components/lc_journal/journal.c`; tests in
  `locker-firmware/tests/host/test_journal.c`, `test_journal_edge.c` and
  `test_journal_retention.c`
- Torn tail: [LevelDB log reader](https://github.com/google/leveldb/blob/main/db/log_reader.cc),
  [SQLite WAL format](https://www.sqlite.org/walformat.html),
  [RocksDB WAL recovery modes](https://github.com/facebook/rocksdb/wiki/WAL-Recovery-Modes),
  [PostgreSQL xlogrecovery.c](https://github.com/postgres/postgres/blob/master/src/backend/access/transam/xlogrecovery.c)
- Two regions and compaction: [littlefs DESIGN.md](https://github.com/littlefs-project/littlefs/blob/master/DESIGN.md)
  and [SPEC.md](https://github.com/littlefs-project/littlefs/blob/master/SPEC.md),
  [ESP-IDF v5.4 NVS](https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/storage/nvs_flash.html),
  [Zephyr NVS](https://docs.zephyrproject.org/latest/services/storage/nvs/nvs.html),
  [Zephyr ZMS](https://docs.zephyrproject.org/latest/services/storage/zms/zms.html),
  [Espressif flash endurance FAQ](https://docs.espressif.com/projects/esp-faq/en/latest/software-framework/storage/nvs.html)
- Index: [Bitcask](https://riak.com/assets/bitcask-intro.pdf),
  [LevelDB filters](https://github.com/google/leveldb/blob/main/doc/index.md)
- Retention: [RFC 4120, section 3.2.3](https://datatracker.ietf.org/doc/html/rfc4120#section-3.2.3),
  [MQTT 3.1.1](https://docs.oasis-open.org/mqtt/mqtt/v3.1.1/os/mqtt-v3.1.1-os.html),
  [mosquitto.conf](https://mosquitto.org/man/mosquitto-conf-5.html),
  [esp-mqtt client](https://github.com/espressif/esp-mqtt/blob/master/mqtt_client.c)
- Erase timing and watchdog: [ESP-IDF v5.4 spi_flash_chip_generic.c](https://github.com/espressif/esp-idf/blob/release/v5.4/components/spi_flash/spi_flash_chip_generic.c),
  [ESP-IDF v5.4 esp_system Kconfig](https://github.com/espressif/esp-idf/blob/release/v5.4/components/esp_system/Kconfig)
