# ADR-0071: RS485 lock panel replies with active door feedback

## Status

Proposed

## Date

2026-10-20

## Context

ADR-0061 defined the proprietary RS485 lock panel protocol from one verified
query-all exchange. Bench tests of the ESP32 client (`locker-firmware/`) on an
SY-800R 8-channel panel at board address 1 showed two things it does not cover.

Captured with a probe on the bench, 9600 8N1:

```text
unlock, latch already open  TX 8A 01 01 11 9B   RX 8A 01 01 00 8A              (+259 ms)
unlock, latch closed        TX 8A 01 01 11 9B   RX 8A 01 01 00 8A 82 01 01 00 82 (+265 ms, 4 of 4)
status reply + door event   TX 80 01 00 33 B2   RX 80 01 00 00 00 33 B2 82 01 01 11 93
latch pushed in by hand                         RX 82 01 01 11 93 (unprompted)
```

1. **Active feedback.** The panel sends `82 <board> <channel> <status> <XOR>` by
   itself whenever a lock's door state changes (door-closing feedback: `00`
   opened, `11` closed). It can arrive directly behind a reply with no gap, so
   a client that ends a frame at the first quiet interval reads both messages
   as one malformed reply. Every unlock of a closed lock was then reported as
   `HARDWARE_ERROR` although the lock opened, and status polls failed.
2. **Unlock reply status.** The seller's protocol description (AliExpress
   listing for the panel, section "RS485 control board Kommunikationsprotokoll")
   defines the unlock reply status by lock type: door-closing feedback `00` =
   unlock succeeded, `11` = unlock failed; door-opening feedback the reverse.
   ADR-0061's clients accepted both values as success.

The same listing calls active feedback an optional, customised function; this
panel ships with it on, so clients must handle it either way.

## Decision

- A received burst is split into messages by their known formats before it is
  interpreted: `8A` and `82` are five bytes, `80` runs to the `33` command
  marker followed by a matching XOR. Bytes that start no valid message are
  skipped. An exchange takes the message that answers its request; `82` door
  events are ignored, since the periodic status poll already reports doors.
- The unlock reply status is judged with the bank's `feedback_type`. A failure
  status is a definite answer: the bus port reports a new result, `REFUSED`,
  the application answers `HARDWARE_ERROR`, and the bus counts as connected (no
  settle period, no cooldown).
- A bus that only echoes our own requests (seen with miswired converters) is not
  detected in code. It is documented by a host test: the unlock echo reads as a
  failure status, so it is never reported as done.

This partially supersedes ADR-0061 for reply framing and unlock status.

## Alternatives Considered

### Alternative A: Use the `82` events as door state

- Pros: faster door updates than polling.
- Cons: optional panel feature; lost events would leave stale state, so polling
  stays necessary anyway.
- Why not chosen: not needed to fix the defects; can be added later.

### Alternative B: Keep framing, accept a reply that merely starts with an ack

- Pros: smallest change for unlocks.
- Cons: status replies with a trailing event still fail; events before a reply
  still break it.
- Why not chosen: fixes only half the observed failures.

### Alternative C: Reject echoed requests in code

- Pros: a miswired bus would report errors instead of plausible data.
- Cons: wiring faults belong to installation, not the protocol; adds a rule the
  real panel never needs.
- Why not chosen: covered by a documenting test instead.

## Consequences

### Positive

- Unlocks of closed locks report success; status polls survive door events.
- A failed unlock is no longer reported to the backend as done.

### Negative

- The bus port gains a result value (`REFUSED`) that every consumer must map.
- On a miswired bus, status polls still read as "all doors open".

### Risks

- A status byte equal to `33` followed by a coincidentally matching XOR could
  end an `80` message early; the board check and the following bytes make this
  unlikely, and the same risk existed before.
- The Pi client (`locker-client/src/adapters/rs485/`) uses the same framing and
  status rule and has the same defects until it is changed the same way.

## Rollout / Migration

Firmware only in this change (`locker-firmware/components/lc_rs485_lock_board/`,
host tests S1-H05 and S1-H32 to S1-H35). The Pi client follows in a separate
change. No MQTT or backend contract changes: `HARDWARE_ERROR` already exists.

## Supersedes / Superseded By

- Supersedes: ADR-0061 in part (reply framing and unlock status)
- Superseded by: none

## References

- Related PRs: #321
- Related issues: #56
- Related docs: `docs/adr/0061-backend-managed-rs485-locker-board-profile.md`,
  `docs/adr/0040-separate-command-acknowledgement-from-door-open-detection.md`,
  `locker-firmware/docs/testing.md`
