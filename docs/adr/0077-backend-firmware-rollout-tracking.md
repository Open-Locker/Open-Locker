# ADR-0077: Backend firmware rollout ends on a heartbeat or a timeout

## Status

Accepted

## Date

2026-10-22

## Context

The ESP32 firmware updates itself on command, over the contract in
[ADR-0076](0076-update-firmware-mqtt-command-and-heartbeat-fields.md). The backend must
let an operator update one locker and track the update to a real end. It follows the
backend's event-sourcing structure: aggregates record events, projectors build read
models, reactors publish MQTT (ADR-0054).

## Decision

We will event-source the rollout status, one update at a time per locker, and end an
update only on evidence from the device or a timeout.

- **Completion only on a heartbeat.** An update is complete when a heartbeat reports
  `firmware_version` equal to the target and `trial: false`. A heartbeat reporting
  `last_rollback_version` equal to the target, after the device accepted, fails it.
- **Timeout.** `locker:expire-firmware-updates` fails an update still in flight 30
  minutes after the request.
- **One at a time, one locker per action.** The service reads the bank under
  `lockForUpdate` in the same transaction that persists the event, and the projector,
  which runs in that transaction, writes the status before the lock is released. No
  bulk or automatic rollout.
- **Refusals are not events.** The operator sees the reason at once, and nothing is
  recorded.
- **Heartbeat fields are telemetry, not events.** They are validated apart from the
  heartbeat. If any is malformed, the heartbeat still counts, and the whole firmware
  part is ignored.

The status transitions, the refusal list and the admin screens are in
`website/src/content/docs/dokumentation/esp32-internals.md`.

## Rationale

Completion on a heartbeat is the only proof the new image booted and survived its trial.
A timeout is needed because the command inbox and ADR-0070 allow no second response; it
is the backstop for a lost failure event and for a restart during the download. The
aggregate cannot hold the one-at-a-time state, because the accepted and failed events come
from the device, outside its stream. A refusal changes nothing in the system, so an event
would only put a failed attempt into the audit log. Accepting a malformed heartbeat means
a bad report cannot make a locker look offline.

## Alternatives Considered

### Alternative A: Mutate the status columns directly on heartbeat

- Pros: less code.
- Cons: violates the event-sourcing rule and loses the rollout history.
- Why not chosen: domain state goes through events. This is about the update status; the
  reported heartbeat fields are written directly.

### Alternative B: Bulk rollout to all lockers

- Pros: convenience.
- Cons: a bad release reaches every unit before anyone sees the failure.
- Why not chosen: one at a time until the pilot shows it is safe.

## Consequences

### Positive

- The installed version of each ESP32 locker is visible in the admin panel.
- An update ends only on evidence from the device, or a timeout.

### Negative

- Every locker's heartbeat goes through the new validation. Pi clients send none of these
  fields.

### Risks

- The one-at-a-time rule depends on a database that honours row locks (PostgreSQL does). On
  one that does not, two simultaneous requests could both pass; the second command is then
  refused by the device (`UPDATE_BUSY`).
- **Open: timeout value.** 30 minutes is to be tuned on the bench.

## Rollout / Migration

New migrations add the `locker_banks` firmware columns. Existing lockers report no
`firmware_version` or capability and stay as they are until a firmware that does is
installed.

## Confirmation

- Requests, refusals and one at a time: `LockerServiceFirmwareUpdateTest`.
- Status and completion: `FirmwareUpdateOutcomeTest`, `FirmwareUpdateAuditTest`,
  `LockerBankFirmwareUiTest`.
- Heartbeat fields: `LockerHeartbeatFirmwareTest`.
- Timeout: `ExpireFirmwareUpdatesTest`.
- On a real unit: HW-H2 and HW-H5.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#336
- Related issues: #59, #70
- Related docs: `website/src/content/docs/dokumentation/esp32-internals.md`,
  [ADR-0075](0075-esp32-ota-firmware-updates.md),
  [ADR-0076](0076-update-firmware-mqtt-command-and-heartbeat-fields.md),
  [ADR-0086](0086-backend-firmware-images-and-download-link.md),
  [ADR-0040](0040-separate-command-acknowledgement-from-door-open-detection.md), ADR-0054,
  ADR-0008
