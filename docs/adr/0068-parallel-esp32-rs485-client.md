# ADR-0068: Parallel ESP-IDF client for the dedicated RS485 lock panel

## Status

Accepted for the integrated bench milestone. Production qualification is pending.

## Date

2026-10-08

## Context

The Pi client already supports a proprietary RS485 panel under ADR-0061. A
working WROOM-32 D1 mini/XY-485 bench project exists in another worktree. Issue
#56's branch-only ESP plan assumed Waveshare hardware and referenced an ADR
number now occupied by another accepted decision. PR #312 proposes unlock/state
interfaces and prohibits retrying uncertain actuation; it remains unmerged at
implementation time. We need a parallel embedded implementation without changing
backend/mobile contracts or removing Pi support.

## Decision

Add `locker-client-esp32/` using pinned ESP-IDF 6.1 and PlatformIO 7.1.3. Preserve
the independent manual project under `hardware/esp32/`. Share MQTT schemas and
acceptance vectors, not Node implementations. Use protocol-neutral unlock/read
interfaces with one application task and one UART owner, one panel per bus.
Keep zero-based mapping, server profile/polarity and canonical config hash from
ADR-0061. Reject unsupported hardware profiles rather than pretending parity.

Commit message/transaction claims together in a raw append-only flash journal
before any unlock; commit outcomes before publishing and delivery after PUBACK.
Represent definitely-not-sent, acknowledged and uncertain transmission outcomes.
Never retry unlock; sensor observation remains separate from acknowledgement.
Interrupted committed claims become unknown outcomes. Torn/corrupt storage stops
actuation, including incomplete claims that may never have reached the UART.

The bench journal retains 64 distinct transactions and 128 message IDs (including
new technical IDs on duplicate transactions) without automatic pruning or
compaction; a full index/partition refuses new work. NVS stores small identity,
credentials and configuration blobs without erase-on-error recovery. This is a
conservative bounded first implementation, not acceptance of production retention,
anti-rollback or power-cut liveness. Time uses SNTP for bench TLS/timestamps and
monotonic timers for operational deadlines; SNTP never expires safety records.

The 4 MB target uses a factory application plus journal partition. OTA, secure
boot, flash encryption, journal compaction and authenticated time/rollback
protection require separate production decisions. Align with #312's hardware
direction without cherry-picking its removal of Pi hardware support. Future
differences must be reconciled through shared fixtures and accepted ADRs.

## Alternatives Considered

- Port the Node runtime: inappropriate runtime/memory/dependency model for ESP32.
- Grow only the manual executable: obscures manufacturing diagnostics and couples
  network/application ownership to the console loop.
- Store every command as one growing NVS blob: raises amplification and power-cut
  consistency concerns; use append-only command records instead.
- Automatically retry timeout unlocks: can physically release the same lock twice.

## Consequences

Most implementation is additive. Backend and mobile interfaces remain unchanged;
MQTT tests validate actual portable C application output against existing schemas.
Bounded limits and conservative corruption handling restrict this image to an
attended bench. Static memory/image size do not establish TLS/runtime headroom.
Physical polarity, timing, grounding, and recovery require recorded HIL evidence.
New provisioning identities invalidate prior mappings while preserving command
history; moving back to Pi requires backend reset and fresh credentials.

## References

- [Issue #56](https://github.com/Open-Locker/Open-Locker/issues/56)
- [Prior plan](https://github.com/Open-Locker/Open-Locker/blob/docs/esp32-client-plan/docs/plans/esp32-locker-client.md)
- [Issue #307](https://github.com/Open-Locker/Open-Locker/issues/307)
- [PR #312](https://github.com/Open-Locker/Open-Locker/pull/312)
- ADR-0040, ADR-0041, ADR-0046, ADR-0048, ADR-0050, ADR-0061
- `locker-client-esp32/README.md`, `locker-client-esp32/HARDWARE_ACCEPTANCE.md`
