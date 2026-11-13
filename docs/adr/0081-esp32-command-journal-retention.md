# ADR-0081: ESP32 command journal retention

## Status

Accepted

## Date

2026-10-16

## Context

A journal ([ADR-0070](0070-esp32-command-journal-format.md)) that is never
pruned fills up and refuses every command. So it has to forget something, and
how late a duplicate can arrive decides what may be forgotten:

- The backend publishes each command once; a queue retry (`--tries=3`) can
  publish the same transaction again under a new message ID shortly after.
- The firmware keeps a persistent MQTT session, so the broker queues commands
  for an offline unit and resends unacknowledged ones on reconnect (MQTT 3.1.1,
  [MQTT-3.1.2-5], [MQTT-4.4.0-1]). The broker configuration sets no
  `persistent_client_expiration`, and Mosquitto's default is to never expire.
- esp-mqtt sends the PUBACK of a command after the event handler returns, and
  the handler only queues the command for the owner task. A crash in between
  loses the command; it never duplicates it.

So duplicates are recent in command count in practice, but nothing bounds them
in time until commands carry an age the device can check (#134).

## Decision

We keep transactions by count, not by age.

- **Open** transactions (claimed with no completion, or completed and not yet
  delivered) are never forgotten. At most 32 are kept. At that limit new claims
  are refused with `UNKNOWN_ERROR`, while stored responses are still replayed
  and delivered.
- **Delivered** transactions are pruned oldest first by log order, and only
  during compaction. The newest 256 are kept, as long as they fit the copy
  budget of half a region (64 KiB).
- **Message IDs** are kept with their transaction, at most 512 in total.
  Compaction keeps later IDs only for open transactions; a delivered one keeps
  just the ID of its claim. At the limit a further ID is not recorded; the
  transaction ID still deduplicates.
- A pruned transaction is forgotten completely. A copy arriving later is a new
  command.

`apply_config`, and the OTA update command, are commands like any other and use
transactions.

**Compaction** runs when the owner task is idle, the active region is more than
75 % full and something was appended since the last one. It also runs before an
append that would not fit the room reserved for open transactions, before a
claim when the index is full (288 transactions or 512 message IDs), and before
any append that follows a torn tail. If the spare is not ready at that moment,
the append is refused: a claim with `UNKNOWN_ERROR`, a further message ID by not
recording it.

A claim is accepted only if the active region has room for it **and** for the
completion and delivered records of every open transaction, each at its largest
size for its IDs.

## Rationale

Bounded retention with a named residual risk forgets less in practice than the
operator erase it replaces. Without dropping later message IDs, repeats under
new IDs could leave 512 IDs in the kept set, so that every compaction ends with
a full index and every claim is refused for good. A copy under a dropped message
ID is still caught by its transaction ID and gets the stored response, which the
backend ignores.

## Alternatives Considered

### Alternative A: Never prune

- Pros: forgets nothing.
- Cons: the unit refuses all commands once the partition is full, and the only
  remedy, erasing the partition, forgets everything at once.
- Why not chosen: see the rationale.

### Alternative B: Retention by age, like the Pi client (30 days)

- Pros: matches ADR-0046.
- Cons: the device has no trusted time before SNTP, and an age limit without a
  matching limit on accepted commands still lets an old queued command arrive
  after its record is gone (RFC 4120, section 3.2.3).
- Why not chosen: it waits for #134, and is then added on top of the count.

## Consequences

### Positive

- The journal runs indefinitely without operator action.

### Negative

- A duplicate that arrives after 256 newer delivered commands is executed again,
  until #134. The redelivery paths above do not produce one, but nothing rules
  it out.
- While the broker is unreachable for long, new commands are refused after 32
  undelivered responses. The limit clears itself as the broker acknowledges
  them.
- With UUID IDs a delivered transaction takes about 220 bytes, so the index
  decides when to compact: about every 30 commands. Each compaction erases one
  region, which gives about 200,000 compactions: about six million commands,
  roughly 1.2 years of continuous commands at ten per minute, or far more than a
  lifetime at a few hundred a day. Restarts add no wear of their own.
- Right after a compaction the spare takes 33 idle ticks to be ready again (at
  least 3.3 s at one per 100 ms tick). A faster burst has its further claims
  refused with `UNKNOWN_ERROR` until then.

### Risks

- Whether the `espressif/mqtt` component sends the PUBACK after the handler, as
  upstream does, is not verified. It decides only whether a crash loses a
  command, never whether it is executed twice.

## Rollout / Migration

None. When #134 lands, a new ADR adds the age condition: a delivered transaction
must then also be older than the bound on accepted commands plus a clock margin,
on SNTP time.

## Confirmation

- Refusing without losing responses: S2-H24, S2-H25.
- What is kept:
  `test_compaction_keeps_open_and_the_newest_delivered_transactions` and
  `test_repeats_under_new_message_ids_never_fill_the_index_for_good` in
  `test_journal_retention`.
- Spare not ready: `test_a_full_region_refuses_claims_until_the_spare_is_ready`.
- Real flash: HW-B6 (600 commands: it compacts, the newest 256 replay, an older
  one runs as new) and HW-G1 (72 hours of opens).

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#321
- Related issues: #56, #134
- Related docs: ADR-0040, ADR-0046
- [RFC 4120, section
  3.2.3](https://datatracker.ietf.org/doc/html/rfc4120#section-3.2.3), [MQTT
  3.1.1](https://docs.oasis-open.org/mqtt/mqtt/v3.1.1/os/mqtt-v3.1.1-os.html),
  [mosquitto.conf](https://mosquitto.org/man/mosquitto-conf-5.html), [esp-mqtt
  client](https://github.com/espressif/esp-mqtt/blob/master/mqtt_client.c)
