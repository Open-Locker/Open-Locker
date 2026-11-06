# ADR-0086: Backend holds signed firmware images and serves them by signed link

## Status

Accepted

## Date

2026-10-22

## Context

A locker that updates over the air needs a place to fetch its image from
([ADR-0075](0075-esp32-ota-firmware-updates.md)). The device has no user, so the
backend's normal authentication does not apply. The backend holds no signing
key: images are signed outside the build
([ADR-0084](0084-esp32-firmware-image-signing.md)) and uploaded signed.

## Decision

The backend stores signed images and serves them on a signed route that names
one release and one locker bank.

- **Releases.** A `firmware_releases` table (a model, no event) holds uuid,
  version (unique, semantic versioning), stored file on the app's default disk,
  size, SHA-256, an `invalid` flag, uploader and upload time. Admins
  (`lockerbank.configure`) upload a signed `.bin` in the Filament panel. An
  image larger than an OTA slot (`0x1D0000`) or whose first byte is not `0xE9`,
  the ESP32 app image magic, is refused. This is not the signature check, which
  stays with the device. A release a bank is still updating to cannot be
  deleted.
- **Route.** `GET /api/firmware/{release}/{lockerBank}` with the `signed`
  middleware. The link is made with `URL::temporarySignedRoute`, valid **15
  minutes**. An expired or tampered link returns 403 and a deleted bank 404.
- **A valid link is not enough.** The image is served only while that bank has
  this release in flight; otherwise 403. An invalid release or a missing file is
  404.
- **Rate limit.** 10 requests a minute per bank, by a named limiter and not per
  address.
- **The link stays out of stored state.** `FirmwareUpdateRequested` holds no
  URL. The reactor makes the link inside its queued job when it publishes, so it
  is in no stored event or failed-job record. The publisher checks the link
  again before sending, and on a refusal logs the length, not the link. The
  route is left out of the OpenAPI document, since only lockers use it.

## Rationale

The signed link is the credential, standing for the decision made when the
update was requested. Keeping it out of the event keeps the event store, which
is replayed and long-lived, free of tokens. The per-bank limit leaves room for
one download and a few retries, and does not make lockers behind one address
compete.

## Alternatives Considered

### Alternative A: Store the image in object storage and give devices a direct link

- Pros: no backend bandwidth.
- Cons: a second system to run and secure for a 1.8 MB file.
- Why not chosen: the signed route needs nothing new.

## Consequences

### Positive

- A leaked link expires in minutes, and stops working once its update ends.

### Negative

- Large uploads and a signed route in a backend that was API-only for devices.
- Disk use grows with every release; old releases are not pruned.

### Risks

- The php-mqtt client logs every published message, link included, at debug
  level when `MQTT_ENABLE_LOGGING` is true (it is false in every example env
  file). Anyone who enables it for debugging in production logs live links for
  15 minutes each.
- `AbstractInboundMqttHandler` logs whole received messages. That is safe
  because the URL goes out on the command topic and a device does not echo it.
- A signed link is about 190 characters with a normal host, so a very long host
  leaves no room in the 256-character limit. Such a request is refused up front.
- **Open: signature check at upload.** Whether the backend verifies the
  signature with the public key to reject a bad upload early (the device always
  checks).
- **Open: link lifetime.** 15 minutes is to be tuned on the bench.

## Rollout / Migration

A new migration adds `firmware_releases`.

## Confirmation

- Route: `FirmwareDownloadTest`.
- Releases: `FirmwareReleaseServiceTest`, `FirmwareReleaseAdminUiTest`.
- The link stays out of logs and traces:
  `test_no_log_line_carries_the_download_link_or_its_signature`,
  `test_the_mqtt_client_does_not_log_what_it_publishes` and
  `test_the_trace_attributes_of_the_publish_leave_out_the_link` in
  `UpdateFirmwarePublisherTest`.
- On a real unit: HW-H2.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs: Open-Locker/Open-Locker#336
- Related issues: #59
- Related docs:
  [ADR-0076](0076-update-firmware-mqtt-command-and-heartbeat-fields.md),
  [ADR-0077](0077-backend-firmware-rollout-tracking.md)
