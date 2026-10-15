# ESP32 command journal: torn tail, compaction and retention (research)

Status: research input for the ADR that supersedes the retention part of
ADR-0068. Nothing here is decided yet. Date: 2026-10-15.

Scope: `locker-firmware/components/lc_journal/journal.c` (ADR-0068), with the
Pi client rules (ADR-0041, ADR-0046), ADR-0002, the firmware MQTT transport
(`lc_mqtt_espidf/transport.c`), the backend publishers, and issue #134.

## Recommendation

| Decision | Recommendation |
| --- | --- |
| Torn tail rule | Damage that reaches the last programmed byte **and** has no valid commit marker (`0xC3`) at its end is an uncommitted write: ignore it. Damage under a valid marker, or followed by any programmed byte, stays fatal. |
| Torn tail cleanup | Drop the `0x00` void-byte overwrite. After a torn tail, the next append compacts into the other region; the debris is erased with the old region. This removes the "second power loss during cleanup kills the journal" case. |
| Compaction scheme | (a) Two regions A/B of 128 KiB each, with a generation counter in a region header that is committed last (littlefs metadata-pair style). Not a sector ring. |
| Retention policy | Prune only `completed` + `delivered` transactions, oldest first (log order, no clock needed), and only when **both** hold: more than K = 256 newer delivered transactions exist, **and** (once #134 exists) the command `timestamp` is older than the staleness bound T plus a clock margin, measured with SNTP time. Without trusted time: no pruning. |
| Non-prunable rule | Claimed-without-completion (unknown outcome) and completed-without-delivery are never pruned. Cap them at U = 32. When U or the room is reached: refuse new claims with `UNKNOWN_ERROR`, keep replaying stored responses and keep delivering; never erase them. They drain on their own once responses are acknowledged. |
| RAM index | Tighten `transaction_id`/`message_id` to UUID in AsyncAPI (contract change, own ADR) and keep 16-byte binary IDs in RAM, about 21 KiB for K + U = 288 transactions and 512 message IDs (today: 43 KiB for 64). If the contract stays free text: 8-byte hash in RAM, full ID compared on flash on every hash hit. |
| Staleness bound dependency | A count bound alone cannot make pruning safe, because the broker queue never expires (see Q3). Safe pruning needs #134: the device rejects commands older than T, so an entry older than T can never be needed again (Kerberos replay-cache rule). Until #134 lands, the count bound K is a residual risk to be accepted explicitly in the ADR. |

## Q1: Torn tail in write-ahead logs

### Established practice

- **LevelDB** treats a short record at the end of the file as the writer dying
  mid-write, not as corruption: a truncated header is reported as EOF
  ("Instead of considering this an error, just report EOF"), and a truncated
  payload makes it "assume the writer died in the middle of writing the
  record. Don't report a corruption." On a checksum mismatch it drops the rest
  of the buffer "since "length" itself may have been corrupted."
  <https://github.com/google/leveldb/blob/main/db/log_reader.cc>; framing in
  <https://github.com/google/leveldb/blob/main/doc/log_format.md>.
- **SQLite WAL recovery** does one pass, verifies each frame's checksum and
  "stops at the end of the file or at the first invalid checksum". Only frames
  up to the last valid commit frame count (`mxFrame`).
  <https://www.sqlite.org/walformat.html>. Checksums are cumulative over all
  previous frames, and transactions commit only with a commit frame.
  <https://www.sqlite.org/fileformat2.html> (section 4.1).
- **RocksDB** offers the choice explicitly. `kTolerateCorruptedTailRecords`
  "ignores any error discovered at the tail of the log", and the docs note it
  cannot tell tail corruption from an incomplete write. `kAbsoluteConsistency`
  treats any error as corruption, for applications that cannot lose a record.
  `kPointInTimeRecovery` (the default since 6.6) stops at the first error.
  <https://github.com/facebook/rocksdb/wiki/WAL-Recovery-Modes>.
- **PostgreSQL** keeps track of a record that is incomplete at the end of WAL
  and ignores it after recovery ("When we find that WAL ends in an incomplete
  record, keep track of that record"); the prev-link check rejects stale
  records left by torn pages.
  <https://github.com/postgres/postgres/blob/master/src/backend/access/transam/xlogrecovery.c>,
  <https://github.com/postgres/postgres/blob/master/src/backend/access/transam/xlogreader.c>.
- **littlefs**: appends never overwrite entries, so "we still have the original
  entries if we lose power during the append". A commit is valid only once its
  CRC is written; a failed commit falls back to the previous state.
  <https://github.com/littlefs-project/littlefs/blob/master/DESIGN.md>
  ("Metadata pairs").

So the standard rule is: an invalid record at the very end is an uncommitted
write and is ignored; corruption with valid data behind it is never silently
skipped by systems that cannot lose records (RocksDB `kAbsoluteConsistency`).

### What it means for us

The ordering in ADR-0068 makes the tail rule safe: an action (unlock, publish,
mark delivered) happens only after the commit marker write has returned. So a
record without a valid marker has had no effect, and ignoring it can only cause
the backend's redelivery to be executed once, never twice.

The one thing RocksDB warns about, tail corruption that looks like an
incomplete write, is handled by the separate marker. A committed record always
ends in `0xC3`. Rule, applied only to the record that reaches the last
programmed byte:

1. Header valid: torn if the marker byte is not `0xC3` (erased or partly
   programmed; partial programming only clears bits, so it reads as a value
   between `0xFF` and `0xC3`). Marker `0xC3` with a bad CRC-32 is corruption.
2. Header invalid or length past the partition: torn if the last programmed
   byte lies within the largest record size (331 bytes) from the record start
   and is not `0xC3`; otherwise corruption. A single bit error in a committed
   record's header leaves the `0xC3` marker as the last byte, so it stays fatal.
   A torn body that happens to end in `0xC3` is called corruption: fail closed.
3. Anything bad that is followed by a programmed byte: corruption, as today.

Today `scan_record` returns `SCAN_CORRUPT` for case 2 (header CRC fails or
length too big at the tail), which a real power cut during the first write can
produce. That is the gap the task describes.

Assumption to keep under hardware test (HW-B4/HW-B5): the flash finishes the
first write before the marker write starts. ESP-IDF partition writes are
synchronous, but write ordering on the part itself is not documented in the
sources above (unverified).

## Q2: Compaction on raw NOR flash

### Established practice

- **littlefs metadata pairs**: two blocks, each with a revision count compared
  "using sequence arithmetic"; the newer one wins. When a block is full,
  compaction copies the current entries to the other block, "erase[s] the
  metadata block and increment[s] the revision count"; until the new commit's
  CRC lands, "we still have everything in our original block". littlefs splits
  a pair above 50 % full to keep the cost of garbage collection at about 2x.
  <https://github.com/littlefs-project/littlefs/blob/master/DESIGN.md>.
- **ESP-IDF NVS (v5.4)**: pages are Empty, Active (one at a time), Full,
  Erasing, Corrupted; entries are 32 bytes whose state goes Empty → Written →
  Erased by clearing bits. To free a page, "Non-erased key-value pairs are being
  moved into another page" and the page is erased; after a power cut "the
  move-and-erase process will be completed upon the next power-on". Paging
  reduces erases relative to writes "by a factor of 126".
  <https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/storage/nvs_flash.html>.
- **Zephyr NVS**: data first, then metadata with a CRC; "Data that is written in
  flash without metadata is ignored". At least two sectors, "one sector is
  always kept empty to allow copying of existing data"; lifetime formula
  `SECTOR_COUNT * SECTOR_SIZE * PAGE_ERASES / (NS * (DS+8))` minutes.
  <https://docs.zephyrproject.org/latest/services/storage/nvs/nvs.html>.
- **Zephyr ZMS**: sector ring, garbage collects ahead "by moving the valid
  ATEs" to the empty sector; "always needs an empty sector"; a per-sector cycle
  counter invalidates old entries; sizing advice: twice the maximum data.
  <https://docs.zephyrproject.org/latest/services/storage/zms/zms.html>.
- **Zephyr FCB**: a FIFO of sectors; `fcb_rotate()` erases the oldest sector
  "if you're done with that data". It does not copy live entries itself.
  <https://docs.zephyrproject.org/latest/services/storage/fcb/fcb.html>.
- **Endurance**: Espressif's FAQ: "Typical flash lifetime is 100 k erase
  cycles", per physical block, and it varies by flash model.
  <https://docs.espressif.com/projects/esp-faq/en/latest/software-framework/storage/nvs.html>.
  The ESP32 datasheet itself does not state it, per Espressif staff on the
  forum (<https://esp32.com/viewtopic.php?p=122471>; forum, not primary).
  Zephyr's examples assume 20,000 cycles on an nRF51 part.

### What it means for us

Recommendation: **(a) A/B regions with a generation counter.**

- The live set is small. With UUID texts a transaction is about 220 bytes on
  flash (claim ~102, completion ~67 without config hash, delivered ~48), plus
  ~85 per extra message ID. K + U = 288 transactions take about 65 KiB, which
  fits a 128 KiB half with room for ~250 more transactions between compactions.
- A transaction is several records (claim, completion, delivered, messages)
  that may span sectors. A sector ring has to copy a partial transaction forward
  while keeping order, which needs per-sector headers, cycle counters and a
  "copied" state per entry (ZMS/NVS machinery). A/B copies the whole retained
  index in one pass using the existing record types, so the only new format is
  a region header.
- The state space for the cut-every-byte host test stays small: two headers
  decide everything. Compaction: erase B fully (always, even if it reads blank,
  because an interrupted erase is not reliably detectable: unverified, so
  assume the worst), write retained records, write B's header with
  generation + 1 and CRC, write B's header commit byte last. Open: the region
  with the valid committed header and newer generation (serial-number
  comparison, as littlefs) wins; the other is garbage. A cut anywhere leaves A,
  a superset of B, which is safe because pruning only removes prunable entries.
- Wear: one region erase per compaction. At HW-G1 load (144 commands/day) a
  region is erased every ~3 days; at 10 commands/minute about 29 times/day,
  still ~9 years to 100k cycles. A sector ring would wear less, but we do not
  need it.
- The flash port needs an `erase` call, and the host fake must model a cut
  erase. ADR-0068's "nothing is ever erased" and the 64-entry negative
  consequence go away; a new ADR supersedes its retention section.
- Sector erase time per 4 KiB is tens of milliseconds on typical SPI NOR parts
  (unverified for our part). Erasing 32 sectors can take seconds, so erase the
  standby region when idle right after a compaction, and re-erase at compaction
  anyway.

## Q3: How long a duplicate can arrive, and retention

### Established practice

- **MQTT 3.1.1**: with Clean Session 0, "the Server MUST store further QoS 1 and
  QoS 2 messages" for the disconnected client [MQTT-3.1.2-5]; on reconnect both
  sides "MUST re-send any unacknowledged PUBLISH Packets" [MQTT-4.4.0-1]; after
  a PUBACK the receiver "MUST treat any incoming PUBLISH packet that contains
  the same Packet Identifier as being a new publication" [MQTT-4.3.2-2]. The
  spec bounds session lifetime only by the session itself and administrator
  action (section 4.1).
  <https://docs.oasis-open.org/mqtt/mqtt/v3.1.1/os/mqtt-v3.1.1-os.html>.
- **Mosquitto**: `persistent_client_expiration` "default if not set is to never
  expire persistent clients"; `max_queued_messages` defaults to 1000 per client,
  `max_inflight_messages` to 20, `persistence` to false.
  <https://mosquitto.org/man/mosquitto-conf-5.html>.
- **Stripe**: keys may be removed "after they're at least 24 hours old", and "We
  generate a new request if a key is reused after the original is pruned".
  <https://docs.stripe.com/api/idempotent_requests>.
- **IETF Idempotency-Key draft (-07)**: the resource "MAY require time based
  idempotency keys" and "SHOULD define such expiration policy"; it does not
  define what happens after expiry (section 2.3).
  <https://datatracker.ietf.org/doc/html/draft-ietf-httpapi-idempotency-key-header>.
- **Kerberos (RFC 4120, 3.2.3)**, the classic replay cache with a bound: the
  server "MUST utilize a replay cache to remember any authenticator presented
  within the allowable clock skew"; older ones get `KRB_AP_ERR_SKEW`; a server
  without its cache after a restart "MUST reject all requests until the clock
  skew interval has passed". <https://datatracker.ietf.org/doc/html/rfc4120>.

Pattern: retention is either time-based with "after expiry it is a new request"
(Stripe; fine for money with reversal, wrong for a physical unlock), or bounded
by an acceptance window the receiver enforces (Kerberos), so that a pruned
entry can never match an accepted message.

### Our redelivery paths (from the repo)

- Firmware: persistent session, QoS 1 subscribe (`disable_clean_session = true`,
  `lc_mqtt_espidf/transport.c`). Backend publishes QoS 1
  (`app/Mqtt/MqttPublisher.php`).
- Broker: `locker-backend/mosquitto/mosquitto.conf.template` sets none of
  `persistent_client_expiration`, `max_queued_messages`, `persistence`. So a
  command can wait for an offline device **indefinitely** (up to 1000 queued),
  and is lost on broker restart.
- Backend: `MqttReactor` is a queued reactor; workers run `--tries=3
  --timeout=90` (`docker-compose*.yml`). A retry republishes the same
  `transaction_id` with a new `message_id` (`MqttPayloadFactory::withMessageId`),
  and that copy can also sit in the broker queue.
- When esp-mqtt sends the PUBACK relative to the application handler was not
  checked (unverified); if before, an unlock interrupted by a power cut is not
  redelivered by the broker, only by a backend retry.

So the time between two copies of a transaction is unbounded today, and the
number of other commands in between is not strictly bounded either (backend
retries interleave with live traffic). A count bound K is only a heuristic.

### What it means for us

- Count-based alone (today's Pi has 10k/30 days, ADR-0046, and explicitly no
  age rejection): leaves the Stripe failure mode, a pruned transaction is
  executed as new. The device cannot even use 30 days before SNTP.
- Time-based alone: impossible without trusted time (firmware has only a
  monotonic clock, `lc_ports/clock_port.h`).
- Combined with the #134 staleness bound (Kerberos rule): the device rejects
  commands whose `timestamp` (already sent by `OpenCompartmentCommandPublisher`)
  is older than T; an entry is prunable once its command timestamp is older
  than T plus a clock margin. Then pruning is provably safe, independent of K.

Proposed policy:

1. Store the command `timestamp` in the claim record (record format v2).
2. Prunable = completed **and** delivered, oldest first, beyond the newest
   K = 256 delivered, and, once #134 is in the contract, older than T + margin
   by SNTP time. No trusted time: prune nothing (the journal keeps working
   until it is full).
3. Never prunable: claimed without completion, completed without delivery
   (ADR-0046 rule 11 and ADR-0041). Their responses are finalised (unknown
   outcome → `UNKNOWN_ERROR`) and redelivered; once acknowledged they become
   prunable.
4. When non-prunable entries reach U = 32 or the region cannot hold the
   retained set plus the reserve: refuse new claims with `UNKNOWN_ERROR`, go on
   replaying and delivering, report it in health/telemetry. No erase, no
   overwrite. The condition clears itself once the broker acknowledges.
5. Message IDs are pruned together with their transaction.
6. Until #134 exists, the ADR has to accept explicitly that a copy older than
   256 delivered transactions would execute again; recommend setting
   `persistent_client_expiration` on the broker to bound it in practice
   (engineering judgement; the value is a product decision).

## Q4: RAM index size

### Facts

- `docs/asyncapi/schemas/common/transaction-id.json` and `message-id.json`:
  `type: string`, `minLength: 1`, no `maxLength`, no `format`. The firmware caps
  IDs at `LC_ID_MAX` 128, which is why each ID costs 129 bytes in RAM.
- The backend generates both as `Str::uuid()` (`LockerBankAggregate`,
  `CompartmentAccessService`, `MqttPayloadFactory`), i.e. 36-character UUIDs.

### Recommendation (engineering judgement, no primary source on this trade-off)

- Preferred: add `format: uuid` to both schemas (JSON Schema 2020-12 defines the
  `uuid` format, <https://json-schema.org/draft/2020-12/json-schema-validation#name-resource-identifiers>,
  as RFC 4122/9562 UUIDs). This is an API/MQTT contract change, so it needs its
  own ADR and a check that the Pi client and backend accept it. RAM per
  transaction: 16 B ID + 16 B first message ID + action (1) + error (1) + flags
  (1) + flash offset of the completion for the config hash (4) ≈ 40 B; extra
  message IDs 18 B each. K + U = 288 transactions and 512 message IDs ≈ 21 KiB.
- Fallback without a contract change: an 8-byte hash per ID in RAM plus the
  flash offset of the record that holds the full text; on a hash hit, read and
  compare the full ID from flash. Equal IDs always hash equal, so a duplicate is
  never missed; a collision costs one flash read. About the same RAM as the
  UUID variant.
- Size K by flash first (two 128 KiB regions, Q2), then RAM follows. K = 256
  with U = 32 fits both with headroom; raising K needs both numbers rechecked.

## Not checked

- esp-mqtt PUBACK timing relative to the event handler.
- Write ordering and behaviour of an interrupted erase on the actual flash part.
- ESP32 datasheet endurance figure (only the Espressif FAQ states 100k).
- littlefs DESIGN.md was read up to about 100k characters; nothing past that is
  cited.

## Follow-up: remaining design decisions

Date: 2026-10-15. Builds on the settled direction above: two 128 KiB regions
A/B, region header with a generation counter committed last, torn tail =
uncommitted, prune only delivered transactions beyond K = 256, non-prunable
capped at U = 32, RAM index with an 8-byte hash per ID. Resolves the first
"Not checked" item (F6).

### F1: RAM index of hashes and flash offsets

Established practice:

- **Bitcask**: the in-memory "keydir" is a hash table "that maps every key in a
  Bitcask to a fixed-size structure giving the file, offset, and size of the
  most recently written entry"; a read "doesn't ever require more than a single
  disk seek". Values stay on disk. <https://riak.com/assets/bitcask-intro.pdf>
- **LevelDB filters**: a few bits "of data in memory per key" (10 in the
  example) cut disk reads for `Get()` by about 100x. A filter only rules keys
  out; a positive is settled by reading the block (implied by the doc, not
  stated). <https://github.com/google/leveldb/blob/main/doc/index.md> ("Filters").

Recommendation:

- Per transaction in RAM: 8-byte hash of `transaction_id`, 4-byte flash offset
  of its claim record, 4-byte offset of its completion record (0 = none),
  action code, error code and `completed`/`delivered` flags (1 byte each):
  20 B with padding. Per message ID: 8-byte hash plus a 2-byte transaction
  slot: 12 B with padding.
- On a hash hit, read the claim (or message) record and compare the full ID.
  Equal IDs always hash equal, so a duplicate is never missed; a collision
  costs one flash read. Any 64-bit non-cryptographic hash (e.g. FNV-1a) is
  enough, because the flash compare settles every hit.
- Keep stored responses on flash, not in RAM (Bitcask model). A replay reads
  the transaction ID text from the claim record and the 65-byte applied config
  hash from the completion record. Replays happen only on redelivery, so these
  reads are off the hot path. Today each `lc_journal_entry_t` holds 129 + 32
  bytes of text plus a 65-byte hash in RAM.
- Estimate: 288 × 20 B + 512 × 12 B ≈ 5.6 KiB + 6.0 KiB ≈ **12 KiB**, against
  about 43 KiB for 64 transactions today (engineering estimate, not measured).

### F2: When to compact, erase time and the watchdog

Established practice:

- **littlefs** compacts lazily, "waiting until we need to compact", and splits
  above 50 % full, which "limits the overhead of garbage collection to 2x the
  runtime cost". <https://github.com/littlefs-project/littlefs/blob/master/DESIGN.md>
- **Zephyr ZMS** collects when a sector fills ("When the current sector is full
  we verify first that the following sector is empty"), and "The application
  could then decide when to switch to the next sector if the current one is
  almost full", i.e. ahead-of-time compaction chosen by the application.
  <https://docs.zephyrproject.org/latest/services/storage/zms/zms.html>
- **ESP-IDF NVS** moves the live entries of a full page and erases it; an
  interrupted move-and-erase is finished at the next power-on.
  <https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/storage/nvs_flash.html>
- **Erase time**: Espressif gives no typical figure; it depends on the flash
  chip and controller ("Different ESP32 modules may have different flash
  chips", <https://docs.espressif.com/projects/esp-faq/en/latest/software-framework/storage/spi-flash.html>).
  The ESP-IDF v5.4 generic driver times out a 4 KiB sector erase at 600 ms and
  a 64 KiB block erase at 4100 ms, "according to GD25Q127(125°) + 100ms",
  i.e. a worst case near 500 ms per sector
  (<https://github.com/espressif/esp-idf/blob/release/v5.4/components/spi_flash/spi_flash_chip_generic.c>).
  Typical datasheet values for common 4 MB SPI NOR parts are tens of
  milliseconds per sector (unverified for our module; measure on hardware).
- **Effect of an erase on the classic ESP32**: the cache is disabled, other
  tasks are suspended, the other core busy-waits, only IRAM interrupts run
  (<https://docs.espressif.com/projects/esp-idf/en/v5.4/esp32/api-reference/peripherals/spi_flash/spi_flash_concurrency.html>).
  `esp_flash_erase_region` issues one command per sector, or per 64 KiB block
  when the remaining range is block aligned, and releases the bus between
  commands (<https://github.com/espressif/esp-idf/blob/release/v5.4/components/spi_flash/esp_flash_api.c>).
  `CONFIG_SPI_FLASH_YIELD_DURING_ERASE` (default y) yields "between erase
  commands" with `vTaskDelay` once commands have run longer than
  `CONFIG_SPI_FLASH_ERASE_YIELD_DURATION_MS` (default 20)
  (<https://github.com/espressif/esp-idf/blob/release/v5.4/components/spi_flash/Kconfig>,
  `spi_flash_os_func_app.c`). It cannot split a single command.
- **Task watchdog**: `CONFIG_ESP_TASK_WDT_TIMEOUT_S` defaults to 5 s and the
  idle tasks of both CPUs are watched by default
  (<https://github.com/espressif/esp-idf/blob/release/v5.4/components/esp_system/Kconfig>).
  The firmware sets `CONFIG_ESP_TASK_WDT_PANIC=y` (`sdkconfig.defaults`), so a
  stall over 5 s reboots. A worst-case 64 KiB block erase (about 4 s) comes
  close; a 4 KiB sector erase does not.

Recommendation:

- **Erase ahead, compact by writing only.** After each compaction (and once
  after boot, F3) erase the spare region in the background: one 4 KiB
  `esp_partition_erase_range` call per owner-task tick (`TICK_MS` = 100), so
  no 64 KiB block command is ever issued and the owner queue (depth 32) keeps
  draining between sectors. 32 sectors take about 3.2 s of ticks plus erase
  time.
- **Trigger**: compact when idle (no command in flight, inbox empty), the
  active region is above 75 % full, and the spare is known erased; compact
  unconditionally when an append would not leave room for the reserve (one
  full transaction plus the pending completions/deliveries of up to U). The
  75 % figure is engineering judgement (littlefs's 50 % is a split rule, not
  this).
- **Where**: the owner task, which already owns the journal. A separate task
  needs a lock around the journal and gains nothing, because flash operations
  stall both CPUs anyway. Compaction then only programs at most ~65 KiB
  (page programs; well under a second, unverified).
- A command arriving during the background erase waits at most one sector
  erase. A command that cannot be queued is dropped, as already designed
  (`owner_post` never waits; the backend times out).

### F3: Interrupted erase and region validity

Established practice:

- Flash vendors: an erase cut by power loss leaves the block in an
  indeterminate state and the remedy is to erase it again (NXP AN4521,
  <https://investors.freescale.com/docs/en/application-note/AN4521.pdf>;
  Renesas AN500 "NOR Flash Memory Erase Operation",
  <https://renesas.com/en/document/apn/nor-flash-memory-erase-operation>; both
  seen via search summaries, not read in full).
  Both describe embedded/parallel NOR; that a cut SPI NOR erase can read as
  0xFF yet be marginal is assumed, not confirmed for our part (unverified).
- littlefs does not trust erased state: the FCRC tag "contains a checksum of
  some amount of bytes in the next commit at the time it was erased", so it
  "only ever program[s] erased bytes, even if a previous commit failed due to
  power-loss". <https://github.com/littlefs-project/littlefs/blob/master/SPEC.md>
- Zephyr ZMS at mount "checks that the sector after this one is empty, or it
  will erase it" (ZMS doc above).
- ESP-IDF never reads back after an erase: `esp_flash_erase_region` returns
  only the command status (`CONFIG_SPI_FLASH_VERIFY_WRITE` covers writes only).

Recommendation:

- Erase the spare's **header sector first**, then the rest. A cut erase then
  always leaves an invalid header, so the region is spare at boot.
- A region is valid only when its header CRC matches and its commit byte is
  set; otherwise it is spare, whatever else it holds.
- "Known erased" lives in RAM only. It is set when all 32 sector erases of this
  boot returned `ESP_OK` and a blank check (read 128 KiB, compare with 0xFF)
  passed. After every reboot the spare is erased again, once: one extra region
  erase per boot, and it covers an erase cut so late that a blank check alone
  might pass.
- Repeat the blank check right before writing a snapshot (a 128 KiB read is
  cheap). On failure erase again; on a second failure mark the journal
  unhealthy (fail closed).

### F4: Snapshot content

Established practice:

- **littlefs**: "If the entry is the most recent we append it to our new
  block"; superseded entries are dropped (DESIGN.md above).
- **ESP-IDF NVS**: "Non-erased key-value pairs are being moved into another
  page" (v5.4 NVS doc above).
- **Zephyr ZMS** moves "the valid ATEs to the N+1 empty sector"; **Zephyr NVS**
  copies pairs that have no newer copy in the sectors still in use
  (<https://docs.zephyrproject.org/latest/services/storage/nvs/nvs.html>).
- **Bitcask** merge writes files "containing only the 'live' or latest
  versions of each present key" (paper above).

All copy live records in their existing format; none adds a summary record
type for compaction.

Recommendation: copy every record of each retained transaction verbatim, in
log order (claim, message, completion, delivered), and drop all records of
pruned transactions. The record CRC covers byte 1 to the end of the payload and
not the offset (ADR-0068 record format), so a copy is a byte copy, and the
existing reader opens a snapshot with no new code. A merged "snapshot" record
saves little (~65 KiB live set in 128 KiB) and adds a second record type to
test against cut writes.

### F5: Generation counter

Established practice: littlefs keeps a 32-bit revision count "Incremented
every erase cycle" and compares it with sequence arithmetic, "very handy for
avoiding problems with integer overflow" (SPEC.md, DESIGN.md above). Zephyr ZMS
uses a `uint8_t` cycle counter per sector and therefore has to handle wrap (ZMS
doc above).

Recommendation: `uint32_t`, first value 1, compared with plain `>`. It rises by
one per compaction and each compaction erases one region; with about 100k
erase cycles per block (Espressif FAQ,
<https://docs.espressif.com/projects/esp-faq/en/latest/software-framework/storage/nvs.html>),
two regions wear out after about 2 × 10^5 generations, far below 2^32
(≈ 4.3 × 10^9). Wrap is unreachable, so sequence arithmetic buys nothing and
plain `>` keeps the cut-every-byte test simple. `0xFFFFFFFF` (erased) is
invalid; the header CRC rejects it anyway. Two valid headers with the same
generation are corruption: fail closed.

### F6: When esp-mqtt sends the PUBACK

Established practice:

- **MQTT 3.1.1 §4.3.2**: the QoS 1 receiver "MUST respond with a PUBACK Packet
  ... having accepted ownership of the Application Message"; "The receiver is
  not required to complete delivery of the Application Message before sending
  the PUBACK"; afterwards the same Packet Identifier is a new publication
  [MQTT-4.3.2-2]. <https://docs.oasis-open.org/mqtt/mqtt/v3.1.1/os/mqtt-v3.1.1-os.html>
- **esp-mqtt** (`mqtt_client.c`, read on `master`): `mqtt_process_receive`
  calls `deliver_publish`, which dispatches `MQTT_EVENT_DATA` once per
  fragment. `esp_mqtt_dispatch_event` posts to the client's event loop, which
  has no task, and runs it inline with `esp_event_loop_run(..., 0)`, so the
  handler runs synchronously in the MQTT task. Only after `deliver_publish`
  returns `ESP_OK` is the PUBACK built (`mqtt_msg_puback`) and written straight
  to the transport, not via the outbox; a failed write aborts the connection.
  DUP publishes are delivered and acknowledged again.
  <https://github.com/espressif/esp-mqtt/blob/master/mqtt_client.c>.
  Same order assumed for the esp-mqtt bundled with ESP-IDF v5.4 (unverified).

What it means for us: our `on_data` (`main/mqtt_session.c`) decodes and posts
to the owner queue without waiting; the claim happens later on the owner task.
So the PUBACK always leaves **before** the journal claim. A crash, or a full
owner queue, between the two loses the command: the broker does not redeliver
it, nothing actuates, the backend gets no response and times out (a backend
retry with a new `message_id` is handled by the journal). It never causes a
second actuation. This matches §4.3.2 and the existing `owner_post` comment
("a lost command gets no unlock"). The journal guarantee (at most one actuation
per `transaction_id`) is unaffected; only "every received command is answered"
does not hold across a crash, and the backend timeout covers that. Claiming
inside the MQTT handler before the PUBACK would close the gap but puts flash
writes into the network task; not recommended now.

### F7: Late duplicates after pruning (residual risk)

RFC 4120 §3.2.3: the server "MUST utilize a replay cache to remember any
authenticator presented within the allowable clock skew", rejects older ones
with `KRB_AP_ERR_SKEW`, and after losing its cache "MUST reject all requests
until the clock skew interval has passed"
(<https://datatracker.ietf.org/doc/html/rfc4120#section-3.2.3>). A bounded
replay cache is provably sufficient only because the receiver refuses
everything outside the window the cache covers. The journal has no such
window: the broker queue never expires (Mosquitto default) and backend retries
reuse the `transaction_id`, so the count bound K cannot prove that a pruned
transaction never returns. Recommendation: the superseding ADR states the
residual risk plainly (a copy arriving after 256 newer delivered transactions
executes again), names #134 (device-side staleness bound on the command
`timestamp`) as the follow-up that makes pruning provably safe, and suggests
broker `persistent_client_expiration` as a practical limit until then.

### F8: Recovery from a genuinely corrupt journal

Established practice:

- **RocksDB** `kAbsoluteConsistency`: any WAL error fails recovery, for
  applications that cannot lose a record
  (<https://github.com/facebook/rocksdb/wiki/WAL-Recovery-Modes>).
- **SQLite** recovery is "a salvage undertaking": "Some content might be
  permanently deleted and unrecoverable" and "Previously deleted content might
  reappear"; it writes a separate database instead of repairing in place
  (<https://www.sqlite.org/recovery.html>).
- **Zephyr ZMS** `zms_mount_force()` can "wipe and reinitialize the partition
  when the first mount attempt fails": recovery by losing everything (ZMS doc
  above).
- **Kerberos** (RFC 4120 §3.2.3, F7): after losing the replay cache, reject
  every request until the skew window has passed. This is the established
  automatic recovery that stays safe; it needs a trusted clock and a
  receiver-enforced window.

Recommendation: keep fail closed (no actuation, report unhealthy) and recover
by operator action: `parttool.py --port <PORT> erase_partition
--partition-name journal` (or reflash), accepting that unknown-outcome
transactions get no response. No automatic repair before #134: a partial
salvage can lose a delivered transaction and execute its redelivery again.
Once #134 and SNTP exist, a Kerberos-style automatic recovery becomes
possible: erase the journal, record the recovery time, and accept only commands
whose `timestamp` is later than the recovery time until T has passed. That
needs its own ADR.
