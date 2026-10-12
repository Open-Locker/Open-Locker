# ADR-0068: ESP32 command journal record format and retention

## Status

Proposed

## Date

2026-10-12

## Context

The Raspberry Pi client keeps its deduplication state in JSON files
(ADR-0046). That state does one safety job: a command whose outcome is known or
uncertain must never be executed a second time, because the second execution is
a second physical unlock. ADR-0040 and ADR-0041 define the behaviour around it:
a repeated transaction replays the stored response, and a command interrupted by
a restart is answered with an unknown outcome.

The ESP32 firmware (`locker-firmware/`, `docs/plans/esp32-rs485-hexagonal-client.md`,
section 3.5) has no filesystem and no atomic rename. Its flash:

- is written in small units, and a write can be cut short by a power loss at any
  byte;
- can only clear bits until a whole sector is erased, and an erase touches
  4 KiB at a time and wears the sector out;
- reads erased bytes as `0xFF`.

The journal has to give the application three guarantees (plan section 2.2):

1. The claim of a command is durable before the unlock is sent.
2. The result is durable before it is published.
3. Delivery is recorded only after the broker has acknowledged the response.

and one rule about failure: a corrupt journal must stop all actuation, because
the firmware can no longer know which commands it has run.

The first version has to be small enough to verify by host tests that cut a
write at every byte, and conservative enough that it never makes an unlock more
likely.

## Decision

The journal is an append-only log in its own flash partition (`journal`, 256 KiB
in `partitions.csv`). It sits behind a two-function flash I/O struct (`read`,
`write`) so that the same code runs on a host against a memory fake. Nothing is
ever erased or rewritten by the journal. The partition is read completely at
start into a bounded index in RAM.

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

Every text is at most 128 bytes and holds no NUL.

### Writing

A record is written as two writes: everything up to the check, then the commit
marker on its own. A record exists only when its marker is present. If either
write fails, the journal marks itself unhealthy in memory and refuses every
further operation until the next start; it does not guess how far the write got.
A call returns true only after the marker is written.

### Reading and torn writes

At start the journal scans from the first byte:

1. `0x00` bytes are skipped (void bytes, below).
2. `0xFF` ends the log, and every byte after it must be `0xFF`.
3. Any other first byte must be the magic.
4. Header and trailer are checked. A header that fails its check, an unknown
   version or type, a payload length above the maximum, a record that does not
   fit the partition, a missing or foreign commit marker, or a failing record
   check **marks the journal unhealthy**.
5. A record whose marker is still erased (`0xFF`), with nothing programmed behind
   it, is the one case that is not corruption: it is a write cut short. It is a
   *torn tail*, it is ignored, and the journal is healthy. Because a claim is
   committed before the unlock is sent, a torn claim means nothing was done, and
   the command can be sent again by the backend. Nothing is wrongly reported as
   done.
6. Each committed record is applied to the index. A record that is intact but
   makes no sense (a claim for a known transaction or message ID, a completion
   or delivery for a transaction that is not in the state to receive it, an
   error code that does not exist) marks the journal unhealthy.

Opening writes nothing.

Appending after a torn tail first writes `0x00` over the debris, so the next
record can start behind it and the scan can skip it (step 1). The first append
after a torn tail therefore performs one extra write. If power is lost again
during that cleanup, the remains cannot be told from corruption and the journal
is unhealthy at the next start. This is deliberate: fail closed, and the
probability is a second power loss within milliseconds of the first.

Every single-bit corruption of a programmed byte is detected: the magic, the
commit marker and the type are compared exactly, the header check covers the
length, and the record check covers the payload. A host test clears every bit of
every programmed byte in turn.

### What unhealthy means

An unhealthy journal answers every command with `UNKNOWN_ERROR` and never calls
the bus. It repairs itself in no way. Recovery is an operator action: erase the
`journal` partition (for example `esptool.py erase_region`) and commission the
device again. The journal is the only state that a network reset (plan slice 5)
keeps.

### Retention and capacity

The first version is bounded and does not prune.

- At most 64 transactions and 128 message IDs are kept. A message ID is kept for
  the claim of its transaction and for each later redelivery of that transaction
  under a new message ID.
- There is no pruning by wall-clock time, no compaction and no automatic erase.
  Time is not trusted on the device before SNTP, and an erase would destroy the
  evidence the journal exists to keep.
- A claim is accepted only if the partition has room for it **and** for the
  completion and delivered records of every claim in the journal that does not
  have them yet, each at its largest size for its IDs. A claim that was accepted
  can therefore always be completed and delivered.
- A message record may use only room that is not reserved this way.
- When the transaction limit or the room is reached, new claims are refused. The
  application answers them with `UNKNOWN_ERROR`; stored responses are still
  replayed, and delivery of stored responses goes on.
- `apply_config` is a command like any other and uses a transaction.

Largest sizes at these limits: a claim record is 301 bytes, a completion 238, a
delivered 140, a message 269. 64 transactions and 64 further message IDs need
about 61 KiB, so the transaction limit, not the 256 KiB partition, is what
fills first. In RAM the index is about 43 KiB (entries with their stored
results, and the message IDs), allocated statically.

## Alternatives Considered

### Alternative A: NVS key-value storage

- Pros: ships with ESP-IDF, handles wear levelling and power loss itself.
- Cons: no multi-record atomicity (a claim and its message ID are two keys),
  garbage collection erases and rewrites pages, and a full store has no
  defined "refuse but keep everything" mode. Entries cannot be shown to be
  uncorrupted from the application.
- Why not chosen: the journal must be able to prove that a record was never
  rewritten, and to be cut at every byte in a host test. NVS stays in use for
  small settings and credentials.

### Alternative B: A filesystem (LittleFS or SPIFFS) on the partition

- Pros: familiar files, built-in wear levelling.
- Cons: copy-on-write and garbage collection rewrite data the journal wants to
  keep verbatim; a large dependency whose power-loss behaviour is not covered by
  our tests; no cheap way to run it on a host in the test gate.
- Why not chosen: more code and more states than an append-only log, for a
  feature the journal does not need.

### Alternative C: A ring log that erases the oldest sector

- Pros: unlimited lifetime without operator action.
- Cons: erasing the oldest sector forgets transactions that may still be
  redelivered by the backend, which makes a second unlock possible. It also
  wears flash with every command.
- Why not chosen: forgetting is the failure the journal exists to prevent. A
  compaction scheme that is safe needs its own decision (below).

### Alternative D: RAM only, with the Pi client's retention

- Pros: trivial.
- Cons: a restart forgets every claim. A command interrupted by a power loss
  would be executed again.
- Why not chosen: it breaks guarantee 1 outright.

## Consequences

### Positive

- The three durability guarantees hold by construction and are tested by cutting
  every write at every byte.
- A corrupt record cannot be mistaken for a valid one by a single bit error.
- The code is small, pure C, and runs on a host unchanged.
- Flash is never erased, so the partition does not wear.

### Negative

- After 64 commands (including `apply_config`) the device refuses new commands.
  This is a hard limit of the first version, and it makes the firmware suitable
  for a bench and an attended pilot only. The partition can be erased by an
  operator.
- A torn write at the wrong moment costs a few bytes; a second power loss during
  cleanup costs the journal.
- 43 KiB of RAM for the index.

### Risks

- Real flash may not fail like the fake: a partially programmed byte, or an
  unordered write. The first is caught by the exact-match bytes and the checks
  (fail closed); both are exercised on the device by hardware checks HW-B4 and
  HW-B5.
- The 64-transaction limit may be hit sooner than expected if the backend sends
  `apply_config` often. HW-G1 (72 hours, an open every ten minutes, 432 commands)
  would exceed it by design, so the test needs either the follow-up below or a
  journal erase between runs. The follow-up decision should come before an
  unattended pilot.

## Rollout / Migration

The record format carries a version byte. A device with a journal of an unknown
version reports it unhealthy and does not actuate. There is no migration from
the Pi client's files: a Pi client and an ESP32 never share a bank at the same
time (plan section 5.1, HW-G2).

## Supersedes / Superseded By

- Supersedes: none. Follows ADR-0046 for the ESP32 firmware.
- Superseded by: none. A compaction or rotation scheme, which has to keep every
  transaction the backend can still redeliver, needs a new ADR that supersedes
  the retention part of this one.

## References

- Related PRs: Open-Locker/Open-Locker#56 (ESP32 RS485 client)
- Related issues: #56, #58
- Related docs: `docs/plans/esp32-rs485-hexagonal-client.md` (sections 2.2, 3.5, 4),
  ADR-0040, ADR-0041, ADR-0046, ADR-0005
- Code: `locker-firmware/components/lc_journal/journal.c`
