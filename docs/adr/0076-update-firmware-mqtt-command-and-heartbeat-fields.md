# ADR-0076: `update_firmware` MQTT command and firmware heartbeat fields

## Status

Proposed

## Date

2026-10-22

## Context

ESP32 lockers update their own firmware over the air ([ADR-0075](0075-esp32-ota-firmware-updates.md),
issue #59). The backend must ask one locker to update, learn what firmware each
locker runs, and know when an update has really finished. Issue #70 asks for the
same for the Pi `locker-client`; this ADR defines a contract both could use, but
only the ESP32 firmware implements it.

The contract in `docs/asyncapi/` is shared by the backend, the Pi client and the
firmware, and changes to it need an ADR (CLAUDE.md). Message id and transaction
id are separate (ADR-0002); a command response only acknowledges the command
(ADR-0040 does the same for door opening).

## Decision

1. **New command `update_firmware`** on `locker/{locker_uuid}/command`, with the
   usual envelope (`message_id`, `transaction_id`, `action`, `timestamp`,
   `data`). `data`:
   - `version` (required): the semantic version the image must report, at most
     31 characters (the size of the image's version field).
   - `url` (required): `https://` only, at most 256 characters (the owner queue
     of the firmware holds copies of every command). Its query string
     carries a short-lived download token.
   - `allow_downgrade` (optional, default `false`).

   The `transaction_id` also deduplicates: a repeated command with the same
   transaction id starts no second download.
2. **One response per command.** A `success` response means *accepted,
   download started*, never *updated*. A refusal is an `error` response with a
   stable `error_code`: `UPDATE_INVALID` (malformed command, non-HTTPS or
   over-long URL), `UPDATE_BUSY` (a command is in flight or an update is
   running), `UPDATE_NOT_READY` (unhealthy journal, bus disconnected, image on
   trial; also the catch-all for any other error on this action),
   `UPDATE_SAME_VERSION`, `UPDATE_DOWNGRADE`, `UPDATE_VERSION_REJECTED` (that
   version was rolled back before). A refusal comes before any download. Both
   responses narrow the generic response to this action
   (`response-update-firmware-success.json`, `response-update-firmware-error.json`).
   The backend's inbox keeps one response per transaction, and ADR-0070 allows
   one completion per transaction, so there is never a second response.
3. **A failure after acceptance is a device event**, not a response: the event
   `firmware_update_failed` on `locker/{locker_uuid}/event`, following
   ADR-0040, with `data.transaction_id` and `data.error_code`
   (`UPDATE_DOWNLOAD_FAILED` or `UPDATE_VERIFY_FAILED`). The running image is
   unchanged. Unlike the detections of ADR-0040, the event is sent once and not
   republished if the broker is not reachable at that moment; the backend's
   timeout (ADR-0077) covers a lost event, and so a restart during the download.
   An image whose header version differs from `version` is a failed
   verification, because the header is read after the command was answered.
4. **New optional heartbeat fields** on `locker/{locker_uuid}/state/heartbeat`:
   - `firmware_version` (string): the running image's version.
   - `trial` (boolean): true until the running image has marked itself valid.
     Required whenever `firmware_version` is present (`dependentRequired`), so a
     heartbeat that omits it can never count as complete.
   - `running_slot` (`ota_0` or `ota_1`).
   - `last_rollback_version` (string): the version this device rolled back from,
     present until the next update.
   - `capabilities` (array of strings): optional features. The only value
     defined is `firmware_update`, sent only by a device whose update adapter is
     wired; a new feature adds a value here.

   A device sends the firmware fields only when it knows its version.

   All are optional as a group, so the Pi client and old firmware stay valid.
   The backend sends `update_firmware` only to lockers whose heartbeat lists
   `firmware_update`.
5. **Completion is a heartbeat, not a response.** The backend treats an update
   as complete only when a heartbeat reports `firmware_version` equal to the
   target and `trial` not true. A heartbeat with the target version and
   `trial: true` means "booted, not yet confirmed".
6. **The URL's query string is a secret.** It is carried only on the device's
   private command topic and appears in no response, heartbeat, event or log.
7. Schemas and examples live in `docs/asyncapi/`
   (`schemas/payloads/command-update-firmware.json`,
   `response-update-firmware-success.json`, `response-update-firmware-error.json`,
   `event-firmware-update-failed.json`, `state-heartbeat.json`, and the
   `examples/*firmware*` files).

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
  backend inbox and breaks the one-completion rule of ADR-0070.
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
  event is lost is only found by the backend's timeout (ADR-0077).
- Download progress is not visible; only accepted, failed or done.

### Risks

- A URL logged by a broker or backend leaks a token. It expires in minutes and
  names one image, and ADR-0075 requires that no component logs it. ADR-0077 (Risks) records that
  no backend log line, stored event or queued job payload carries the URL, and
  which tests guard it.

## Rollout / Migration

Additive. The backend merges first; lockers without `firmware_update` in
`capabilities` are never sent the command.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: #59, #70
- Related docs: [ADR-0075](0075-esp32-ota-firmware-updates.md),
  [ADR-0077](0077-backend-firmware-releases-and-rollout-tracking.md), ADR-0002,
  ADR-0008, [ADR-0040](0040-separate-command-acknowledgement-from-door-open-detection.md),
  [ADR-0070](0070-esp32-command-journal-record-format-and-retention.md),
  `docs/asyncapi/mqtt.yaml`, `docs/locker-command-scenarios.md`
