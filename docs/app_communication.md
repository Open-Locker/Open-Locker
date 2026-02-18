# App Communication Guide

This document describes how client applications (mobile app, web app, admin UI)
communicate with the backend for compartment opening.

## 1) Communication Channels

- **REST API (OpenAPI / Scramble)** for request/response operations.
- **Realtime push (Laravel Reverb + Laravel Echo)** for live status updates.
- **Polling fallback** when realtime is unavailable.

## 2) REST API (OpenAPI)

The REST contract is documented via Scramble-generated OpenAPI docs.

Relevant endpoints:

- `POST /api/compartments/{compartment}/open`
  - Starts an open command.
  - Returns `202` (`pending`) or `403` (`denied`) with `command_id`.
- `GET /api/compartments/open-requests/{commandId}`
  - Returns current command state (`accepted|denied|sent|opened|failed`).
  - Used as fallback when realtime is not available.
- `GET /api/compartments/accessible`
  - Returns all compartments the current user can access, grouped by locker bank.

## 3) Realtime Push (Echo + Reverb)

OpenAPI does not model websocket subscriptions directly, so the realtime
contract is documented here.

### 3.1 Channel and Auth

- **Private channel**: `users.{userId}.compartment-open`
- **Broadcast auth endpoint**: `POST /broadcasting/auth`
- Channel authorization is defined in `routes/channels.php`.

### 3.2 Event

- **Broadcast event name**: `.compartment.open.status.updated`
- **Server event class**: `App\Events\CompartmentOpenStatusUpdated`

### 3.3 Event payload

```json
{
  "command_id": "uuid",
  "compartment_id": "uuid",
  "status": "accepted|denied|sent|opened|failed",
  "error_code": "nullable-string",
  "message": "nullable-string"
}
```

## 4) Recommended Client Flow

1. Call `POST /api/compartments/{id}/open`.
2. Use returned `command_id` as correlation ID in client state.
3. Subscribe via Echo to `users.{userId}.compartment-open`.
4. Handle `.compartment.open.status.updated` events for this `command_id`.
5. If websocket is unavailable, poll
   `GET /api/compartments/open-requests/{commandId}` until final state.

Final states:

- `opened` (success)
- `failed` (device/hardware failure)
- `denied` (authorization denied)

Intermediate states:

- `accepted`
- `sent`

## 5) Frontend Requirements

- Configure Laravel Echo with Reverb connection settings.
- Ensure authenticated requests for private channel subscription.
- Keep command status UI keyed by `command_id`.
- Implement reconnect + polling fallback behavior.
