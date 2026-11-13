# ADR-0076: `update_firmware` MQTT command and firmware heartbeat fields

## Status

Accepted

## Date

2026-10-22

## Context

ESP32 lockers update their own firmware over the air
([ADR-0075](0075-esp32-ota-firmware-updates.md), issue #59). The backend must
ask one locker to update, learn what firmware each locker runs, and know when an
update has really finished. Issue #70 asks for the same for the Pi
`locker-client`; this ADR defines a contract both could use, but only the ESP32
firmware implements it.

The contract in `docs/asyncapi/` is shared by the backend, the Pi client and the
firmware. Message ID and transaction ID are separate (ADR-0002); a command
response only acknowledges the command (ADR-0040 does the same for door
opening).

## Decision

An update is one command, one response, an optional failure event, and a
heartbeat that proves the result.

1. **New command `update_firmware`** on `locker/{locker_uuid}/command`, with the
   usual envelope. `data`:
   - `version` (required): the semantic version the image must report, at most
     31 characters.
   - `url` (required): `https://` only, at most 256 characters. Its query string
     carries a short-lived download token.
   - `allow_downgrade` (optional, default `false`).

   A repeated command with the same `transaction_id` starts no second download.
2. **One response per command.** A `success` response means *accepted, download
   started*, never *updated*. A refusal is an `error` response, before any
   download, with a stable `error_code`:

   | Code | Meaning |
   | --- | --- |
   | `UPDATE_INVALID` | Malformed command, non-HTTPS or over-long URL |
   | `UPDATE_BUSY` | A door is being watched or an update is running |
   | `UPDATE_NOT_READY` | Unhealthy journal, bus disconnected, image on trial, running version not comparable; also the catch-all for any other error on this action |
   | `UPDATE_SAME_VERSION` | The running version |
   | `UPDATE_DOWNGRADE` | Lower than the running version, without `allow_downgrade` |
   | `UPDATE_VERSION_REJECTED` | That version was rolled back before |
3. **A failure after acceptance is a device event**, not a response:
   `firmware_update_failed` on `locker/{locker_uuid}/event`, with
   `data.transaction_id` and `data.error_code` (`UPDATE_DOWNLOAD_FAILED` or
   `UPDATE_VERIFY_FAILED`). The running image is unchanged. Unlike the
   detections of ADR-0040, it is sent once and not republished; the backend's
   timeout ([ADR-0077](0077-backend-firmware-rollout-tracking.md)) covers a lost
   event.
4. **New optional heartbeat fields** on `locker/{locker_uuid}/state/heartbeat`:
   - `firmware_version`: the running image's version.
   - `trial` (boolean): true until the running image has marked itself valid.
     Required whenever `firmware_version` is present (`dependentRequired`), so a
     heartbeat that omits it can never count as complete.
   - `running_slot` (`ota_0` or `ota_1`).
   - `last_rollback_version`: the version this device rolled back from, present
     until the next update.
   - `capabilities` (array of strings): the only value defined is
     `firmware_update`, sent only by a device whose update adapter is wired. The
     backend sends `update_firmware` only to lockers that list it.
5. **Completion is a heartbeat, not a response.** An update is complete only
   when a heartbeat reports `firmware_version` equal to the target and `trial`
   not true.
6. **The URL's query string is a secret.** It is carried only on the device's
   private command topic and appears in no response, heartbeat, event, log line,
   stored event or queued job payload, on the device or the backend.

Schemas and examples are in `docs/asyncapi/`.

## Rationale

A distinct command keeps the update separate from `apply_config`. Reusing the
response envelope and the heartbeat needs no new topic, and the backend already
tracks both. Ending the flow on a heartbeat means a device that accepted an
update and then died, or rolled back, is never reported as updated. Optional
fields keep the contract backward compatible.

## Alternatives Considered

### Alternative A: Report completion or a late failure in a second response

- Pros: one message, no heartbeat dependency.
- Cons: the response is sent before the restart and cannot know the new image
  survives its trial; a second response per transaction is discarded by the
  backend inbox and breaks the one-completion rule of the journal (ADR-0070).
- Why not chosen: success is the heartbeat and a late failure is an event.

### Alternative B: A new `locker/{uuid}/firmware` topic for update status

- Pros: progress reporting without touching the heartbeat.
- Cons: new topic and broker ACL; the pilot needs no progress bar.
- Why not chosen: not needed for the safety rules; can be added later.

### Alternative C: Reuse the name `apply_update` from issue #70

- Pros: matches the issue's wording.
- Cons: #70 describes a container update for the Pi client; this command carries
  an image URL and a signed-image policy.
- Why not chosen: `update_firmware` says what it does. A Pi-client command can
  get its own name.

## Consequences

### Positive

- The Pi client and the backend's command-response handling are unaffected: one
  response per transaction.
- The backend can show the installed version per locker for the first time.

### Negative

- The backend needs a handler for `firmware_update_failed`, and a failure whose
  event is lost is only found by the timeout.
- Download progress is not visible; only accepted, failed or done.

### Risks

- A URL logged by a broker or backend leaks a token. It expires in minutes and
  names one image.

## Rollout / Migration

Additive. The backend merges first; lockers without `firmware_update` in
`capabilities` are never sent the command.

## Confirmation

- Command and refusals: S7-H02 to S7-H05, S7-H11;
  `test_update_firmware_publisher_builds_a_payload_that_matches_the_contract` in
  `UpdateFirmwarePublisherTest`.
- Failure event: S7-H06; `FirmwareUpdateOutcomeTest`.
- Heartbeat fields and schemas: S7-H13; the host gate's schema validation of
  every emitted message in `scripts/verify.py`; `LockerHeartbeatFirmwareTest`.
- Completion on a heartbeat: `FirmwareUpdateOutcomeTest`; HW-H2 and HW-H6.
- The URL as a secret: S7-H12;
  `test_no_log_line_carries_the_download_link_or_its_signature` in
  `UpdateFirmwarePublisherTest`.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#336
- Related issues: #59, #70
- Related docs: [ADR-0075](0075-esp32-ota-firmware-updates.md),
  [ADR-0077](0077-backend-firmware-rollout-tracking.md), ADR-0002, ADR-0008,
  [ADR-0040](0040-separate-command-acknowledgement-from-door-open-detection.md),
  [ADR-0070](0070-esp32-command-journal-format.md), `docs/asyncapi/mqtt.yaml`,
  `docs/locker-command-scenarios.md`
