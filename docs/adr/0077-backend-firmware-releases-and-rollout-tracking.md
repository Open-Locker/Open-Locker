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

1. **`firmware_releases` table** (model, no event): uuid, version (unique,
   semantic versioning, checked when it is saved), stored image file on the
   app's default disk, size, SHA-256, an `invalid` flag, uploader and upload time
   (`created_at`). Operators upload an already signed image in the Filament panel
   and can mark a release invalid, which hides it from the update action and
   stops its download.
2. **Download route.** `GET /api/firmware/{release}/{lockerBank}` with the
   `signed` middleware serves a release's image to a locker. The link is made
   with `URL::temporarySignedRoute`, valid 15 minutes, naming one release and one
   locker bank. An expired or tampered link returns 403.
   - **The signed link is the credential.** There is no auth guard and no
     Policy, because the device has no user; the signature stands for the
     decision made when the update was requested. A deleted bank gives 404.
   - **A valid link is not enough.** The image is served only while that bank
     has this release in flight (*requested* or *downloading*); otherwise 403. A
     link that outlives its update, or was made for another update, is refused
     like any bad link. An invalid release or a missing file is 404.
   - **Rate limit.** 10 requests a minute per bank (a named limiter, not per
     address), which leaves room for the one download and a few retries of a
     device whose connection dropped, and does not make lockers behind one
     address compete.
   - The route is left out of the OpenAPI document: only lockers use it.
3. **Requesting an update.** The admin action "Update firmware" on a locker bank
   calls `LockerService::requestFirmwareUpdate()`, which reads the bank again
   under a lock and asks the aggregate, which records `FirmwareUpdateRequested`
   (locker, release id, version, transaction id, `allow_downgrade`, actor). The
   event holds **no URL**. A reactor builds the signed URL and publishes
   `update_firmware` through a typed publisher in `app/Mqtt/Publishers/`
   (ADR-0008).
   - **Refusals.** A bank that is not provisioned, whose heartbeat did not
     advertise `firmware_update` (`firmware_update_capable`), with an update
     already in flight, an invalid release, or a download link the firmware
     would refuse: not an `https://` URL, or longer than **256 characters**, the
     limit of the command (ADR-0076). The last is checked when the request is
     made, because the public URL decides it; a signed link is about 190
     characters with a normal host, so a very long host leaves no room. The
     publisher checks again before it sends and then logs the length, not the
     link, and sends nothing.
   - **A refusal is not stored as an event.** It changes nothing in the system,
     and the operator who asked sees the reason at once; an event would only
     put a failed attempt into the audit log and the event history, and the
     request can simply be made again.
4. **Rollout status is event-sourced.** The command response and the
   `firmware_update_failed` event move the state: success to *downloading*, an
   error response or the failure event to *failed* (with the error code). A
   projector writes the state onto firmware columns of `locker_banks`
   (`firmware_update_status`, transaction id, release id, target version, error
   code, requested time; the capability and the reported version come from the
   heartbeat). It acts only for the transaction the bank tracks, so a late or
   old event for another transaction is history. The response is derived in
   `CommandResponseReactor`, behind the inbox's one-response rule, and only for
   an update this backend requested from that bank: a response from another bank
   that carries the same transaction id changes nothing and does not use up the
   real one. The failure event is derived in `FirmwareUpdateReactor`, once per
   transaction and under the same single-worker `events` queue condition as
   ADR-0040.
5. **Completion only on a heartbeat.** The update is *completed* when a
   heartbeat reports `firmware_version` equal to the target and `trial: false`;
   a heartbeat with `trial` missing or true never completes it (ADR-0076), and
   one with the target version on trial leaves the update in flight (there is no
   "on trial" status; the trial flag is stored for display). A heartbeat
   reporting `last_rollback_version` equal to the target marks the update
   *failed* after acceptance, that is while it is *downloading*. Before
   acceptance a unit that rolled this version back earlier still reports it, and
   the device refuses the version itself (`UPDATE_VERSION_REJECTED`), which is
   what fails a retry. Completion and rollback are events
   (`FirmwareUpdateCompleted`, `FirmwareUpdateFailed`), derived by
   `FirmwareUpdateOutcomeService`; the event moves the
   status, so a later heartbeat records nothing again. The heartbeat fields
   themselves are not event-sourced: `LockerHeartbeatHandler` writes them to
   `locker_banks` (`firmware_version`, `firmware_trial`, `firmware_running_slot`,
   `firmware_last_rollback_version`, `firmware_reported_at`, and
   `firmware_update_capable` from `capabilities`), as it does `modbus_connected`.
   A heartbeat without them leaves the stored values alone; a report replaces
   all of them; `capabilities` without `firmware_update` clears the capability.
   The firmware fields are telemetry and are validated apart from the heartbeat
   itself (`modbus_connected` stays as it was): if any is malformed, including
   `trial` missing with `firmware_version`, `trial` not a real JSON boolean, or
   `capabilities` not a list of strings, the heartbeat is accepted anyway (last
   heartbeat, connection state and Modbus fields are updated), a warning names the
   fields but not the payload, and the whole firmware part is ignored: nothing
   stored, no completion, no rollback. A bad report must not make a locker look
   offline. The reason `FirmwareUpdateFailed` carries when the
   backend ends an update itself is a `reason` field (`rollback`, `timeout`,
   the values of `FirmwareUpdateFailureReason`) beside `errorCode`, which keeps
   the device's `UPDATE_*` codes; the projector stores them in separate columns,
   `firmware_update_error_code` and `firmware_update_failure_reason`. An update
   that already timed out stays failed even if its heartbeat arrives later; the
   reported version is still shown.
6. **Timeout.** A scheduled command (`locker:expire-firmware-updates`, every
   minute without overlap, like `DetectOfflineLockers`) fails an update still in
   flight 30 minutes after the request, by recording `FirmwareUpdateFailed` with
   reason `timeout`. It reads each bank again before recording, and a failed
   update is no longer in flight, so each update times out once. The timeout is
   the backstop for everything the device cannot report: a lost failure event
   (the device sends it once and does not retry), and a restart after acceptance
   but before the download ended, which leaves nothing on the device to report
   (ADR-0075).
7. **One update at a time per locker, and one locker per action.** A new request
   is refused while one is pending. No bulk or automatic rollout. The aggregate
   cannot hold this state: the *accepted* and *failed* events come from the
   device, outside its stream, so it would never learn that an update ended.
   The service therefore reads the bank under `lockForUpdate` in the same
   transaction that persists the event, and the projector (which runs in that
   transaction) writes the status before the lock is released, so two requests
   at once cannot both see an idle bank.
8. **The URL is a secret on the backend too.** It must not appear in logs,
   stored events, queued job payloads or traces (see Risks).
9. Admin UI, admin-only (`lockerbank.configure`, like the locker bank
   settings; managers do not see it). The release resource uploads a signed
   `.bin` with its version (semantic versioning, unique) and refuses an image
   larger than an OTA slot (`FirmwareRelease::MAX_IMAGE_BYTES`, 0x1D0000, from
   `locker-firmware/partitions.csv`) or whose first byte is not `0xE9`, the
   ESP32 app image magic (this is not the signature check, which stays with
   the device). `FirmwareReleaseService` does the work, so the pages only call
   it: the server computes the SHA-256 and size from the stored bytes, inserts
   the row (its unique version decides a race) and writes
   `firmware/{version}.bin` in the same transaction, never over an existing
   file, and removes the temporary upload on every path. A release can be
   marked invalid, and one that a bank is still updating to cannot be deleted
   (the action is hidden, the service refuses, and the model refuses as a
   backstop). The locker
   bank list shows the reported version and the update status; the edit view
   shows slot, trial, last rollback version, target, error code and failure
   reason. "Update firmware" (a release picker and an `allow_downgrade` toggle)
   appears only for banks that reported the capability, and a refusal is a
   translated notification. Audit presenter entries and translations cover the
   new events.

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
- The one-at-a-time rule depends on a database that honours row locks
  (PostgreSQL does). On a database that does not, two simultaneous requests could
  both pass; the second command is then refused by the device (`UPDATE_BUSY`).
- Operators must upload signed images; the backend cannot sign for them.

### Risks

- **URL logging in the backend.** Span attributes never
  carry payload bodies (`MqttSpanAttributes`; a test covers an
  `update_firmware` payload), `MqttTraceContext` only adds `traceparent`, and
  `MqttTopicRedactor` redacts topics only. `UpdateFirmwareCommandPublisher` logs
  the transaction, release id and version, never the URL, and a test fails if
  any log line it writes holds the signature, `expires=` or `https://`. The
  generic inbound handler (`AbstractInboundMqttHandler`) logs whole received
  messages, which is safe because the URL goes out on the command topic and a
  device does not echo it. The reactor is queued and its job holds only the
  `FirmwareUpdateRequested` event, which carries the release id; the URL is made
  inside the job and never leaves it, so failed-job records hold none. The
  php-mqtt client logs every published message, link included, at debug level
  when `MQTT_ENABLE_LOGGING` is true (it is false in every example env file); a
  test fails if any MQTT connection turns it on by default. Anyone who enables
  it for debugging in production logs live download links for 15 minutes each.
- **Open: backend signature check at upload.** Whether the backend verifies the
  image signature with the public key to reject a bad upload early (proposal:
  later; the device always checks).
- **Open: timeout and token values** (15 minutes link, 30 minutes timeout) are
  proposals to tune on the bench.
- Disk use grows with every release; old releases are not pruned.

## Rollout / Migration

New migrations for `firmware_releases` and the `locker_banks` firmware columns.
Existing lockers report no `firmware_version` or capability and stay as they are
until a firmware that does is installed.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: #59, #70
- Related docs: [ADR-0075](0075-esp32-ota-firmware-updates.md),
  [ADR-0076](0076-update-firmware-mqtt-command-and-heartbeat-fields.md),
  [ADR-0070](0070-esp32-command-journal-record-format-and-retention.md),
  [ADR-0040](0040-separate-command-acknowledgement-from-door-open-detection.md),
  ADR-0054, ADR-0008
