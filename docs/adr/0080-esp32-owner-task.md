# ADR-0080: One owner task runs every ESP32 use case

## Status

Accepted

## Date

2026-10-21

## Context

The firmware receives input from several tasks: the MQTT client, the USB
console, the setup network, the reset button and the OTA download. A command, an
`apply_config` and an acknowledgement must never interleave, because the safety
rules depend on the order of journal writes and bus calls. A microcontroller
also cannot let queues grow without limit.

## Decision

Every use case runs on one owner task. Other tasks never call a use case; they
post to its bounded inputs and never wait:

- a control queue of small events (12 bytes, 32 deep);
- a pool of 32 command slots, decoded in place, so no command is copied;
- two one-slot mailboxes, for the setup request and the provisioning reply.

Overflow never actuates:

- A command with no free slot is dropped and logged before anything is claimed.
  The backend times out.
- A control event that finds the queue full is lost and logged. A lost
  acknowledgement leaves the response undelivered, and it is published again
  after the publish timeout (60 s).
- A setup request or provisioning reply that finds its mailbox busy is refused,
  and the sender is told.

The order per step, the semaphore and the 24 KiB budget asserted at build time
are in
`website/src/content/docs/dokumentation/esp32-internals.md`
and `main/owner_queue.h`.

## Rationale

One task is the simplest concurrency rule that makes "a lock is never released
twice" hold by construction. Separate inputs mean a burst of acknowledgements
cannot push out a command, and a full pool cannot lose an acknowledgement.

## Alternatives Considered

### Alternative A: One queue of full-size events

- Pros: one input, simple to read.
- Cons: every event costs 688 bytes, the size of the largest; a burst of
  acknowledgements can push out commands.
- Why not chosen: it was the first design, and it was replaced for this reason.

## Consequences

### Positive

- `apply_config` cannot race an unlock.
- The application needs no locks.

### Negative

- A long bus wait delays every other event. The wait is bounded by the bus
  deadline.
- A command dropped after the PUBACK is not redelivered, so the backend only
  sees a timeout.

### Risks

- At about 4.5 s per unlock on a silent panel, the 32nd queued command runs
  about 2.5 minutes late, and nothing refuses it for being old. Answering
  instead of dropping, and an age check, need a new error code in the MQTT
  contract, and so their own ADR.

## Rollout / Migration

None. No unit is deployed.

## Confirmation

- S2-H50 (events run one at a time, in arrival order).
- S2-H54 to S2-H58 in `test_owner_queue` (overflow and order of the inputs).
- HW-C9: minimum free heap and owner task stack headroom.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#321, Open-Locker/Open-Locker#329
- Related issues: #56
- Related docs: `website/src/content/docs/dokumentation/esp32-internals.md`,
  [ADR-0072](0072-esp32-locker-firmware.md)
