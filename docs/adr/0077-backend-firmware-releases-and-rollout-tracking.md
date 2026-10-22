# ADR-0077: Backend firmware releases and rollout tracking

## Status

Proposed

## Date

2026-10-22

## Context

The ESP32 firmware updates itself on command ([ADR-0075](0075-esp32-ota-firmware-updates.md)),
over the contract in [ADR-0076](0076-update-firmware-mqtt-command-and-heartbeat-fields.md).
The backend must hold the signed images, let an operator update one locker, give
the device a download link, and track the update to a real end. It follows the
backend's event-sourcing structure: aggregates record events, projectors build
read models, reactors publish MQTT (CLAUDE.md, ADR-0054).

The backend holds no signing key. Images are signed outside the build and
uploaded signed.

## Decision

1. **`firmware_releases` table** (model, no event): version (unique), stored
   image file, size, SHA-256, an `invalid` flag, uploader and upload time.
   Operators upload an already signed image in the Filament panel and can mark a
   release invalid, which hides it from the update action.
2. **Download route.** A signed GET route serves a release's image to a locker.
   The link is made with `URL::temporarySignedRoute`, valid 15 minutes, naming
   one release and one locker. An expired or tampered link returns 403.
3. **Requesting an update.** The admin action "Update firmware" on a locker bank
   calls the aggregate, which records `FirmwareUpdateRequested` (locker, release
   id, version, transaction id, `allow_downgrade`). The event holds **no URL**.
   A reactor builds the signed URL and publishes `update_firmware` through a
   typed publisher in `app/Mqtt/Publishers/` (ADR-0008). The action is offered
   only for a locker whose heartbeat lists `firmware_update`.
4. **Rollout status is event-sourced.** The command response and the
   `firmware_update_failed` event move the state: success to *downloading*, an
   error response or the failure event to *failed* (with the error code). A
   projector writes the state onto firmware columns of `locker_banks`
   (target version, status, transaction id, requested time, and the version the
   latest heartbeat reported).
5. **Completion only on a heartbeat.** The update is *completed* when a
   heartbeat reports `firmware_version` equal to the target and `trial: false`;
   a heartbeat with `trial` missing or true never completes it (ADR-0076). A
   heartbeat reporting a different version after acceptance, with
   `last_rollback_version` set to the target, marks it *failed*.
6. **Timeout.** A scheduled command (like `DetectOfflineLockers`) fails an
   update with no completion 30 minutes after the request, by recording a
   failure event. A lost failure event is therefore found by the timeout.
7. **One update at a time per locker, and one locker per action.** A new request
   is refused while one is pending. No bulk or automatic rollout.
8. **The URL is a secret on the backend too.** It must not appear in logs,
   stored events, queued job payloads or traces (see Risks).
9. Admin UI: release list and upload form, the "Update firmware" action, and the
   firmware version and update status in the locker bank view. Audit presenter
   entries and translations cover the new events.

## Rationale

Keeping the URL out of the event keeps the event store (replayed, long-lived)
free of tokens; the reactor mints a fresh link when it publishes. Completion on a
heartbeat is the only proof the new image booted and survived its trial. A
timeout is needed because the command inbox and ADR-0070 allow no second response.

## Alternatives Considered

### Alternative A: Store the image in object storage and give devices a direct link

- Pros: no backend bandwidth.
- Cons: a second system to run and secure for a 1.8 MB file.
- Why not chosen: the signed route needs nothing new.

### Alternative B: Mutate the firmware columns directly on heartbeat

- Pros: less code.
- Cons: violates the event-sourcing rule and loses the rollout history.
- Why not chosen: CLAUDE.md requires events for domain state.

### Alternative C: Bulk rollout to all lockers

- Pros: convenience.
- Cons: a bad release reaches every unit before anyone sees the failure.
- Why not chosen: one at a time until the pilot shows it is safe.

## Consequences

### Positive

- The installed version of each ESP32 locker is visible in the admin panel.
- An update ends only on evidence from the device.

### Negative

- Large uploads and a signed route in a backend that was API-only for devices.
- Operators must upload signed images; the backend cannot sign for them.

### Risks

- **Open: URL logging in the backend (slice 6 must handle).** Checked on this
  branch, no backend code changed: span attributes never carry payload bodies
  (`MqttSpanAttributes`), `MqttTraceContext` only adds `traceparent`, and
  `MqttTopicRedactor` redacts topics only. But the existing outbound publishers
  log their own chosen fields (`ApplyConfigCommandPublisher`) so the new
  publisher must not log the URL, and the generic inbound handler
  (`AbstractInboundMqttHandler`) logs the whole received message and rejected
  payloads. The command goes out on the command topic, not inbound, so the URL
  is safe unless a device echoes it. The reactor's queued job and any failed-job
  record also serialize the event; the event holds no URL, but the publisher
  must not pass the URL through a queued job either.
- **Open: backend signature check at upload.** Whether the backend verifies the
  image signature with the public key to reject a bad upload early (proposal:
  later; the device always checks).
- **Open: timeout and token values** (15 minutes link, 30 minutes timeout) are
  proposals to tune on the bench.
- Disk use grows with every release; old releases are not pruned.

## Rollout / Migration

New migrations for `firmware_releases` and the `locker_banks` firmware columns.
Backend slices: release table, request, publish and download; then heartbeat,
completion and timeout; then admin UI. Existing lockers report no
`firmware_version` and stay as they are.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: #59, #70
- Related docs: [ADR-0075](0075-esp32-ota-firmware-updates.md),
  [ADR-0076](0076-update-firmware-mqtt-command-and-heartbeat-fields.md),
  [ADR-0070](0070-esp32-command-journal-record-format-and-retention.md),
  ADR-0054, ADR-0008
