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
  - Returns current command state (`accepted|denied|sent|acknowledged|opened|already_open|door_jammed|failed`).
  - Used as fallback when realtime is not available.
- `GET /api/compartments/accessible`
  - Returns all compartments the current user can access, grouped by locker bank.

## 3) Realtime Push (Echo + Reverb)

OpenAPI does not model websocket subscriptions directly, so the realtime
contract is documented here.

### 3.1 Channel and Auth

- **Private channel**: `users.{userId}.compartment-status`
- **Broadcast auth endpoint**: `POST /broadcasting/auth`
- Channel authorization is defined in `routes/channels.php`.

### 3.2 Events

All compartment-related push notifications use this channel:

- **Open command progress**
  - **Broadcast event name**: `.compartment.open.status.updated`
  - **Server event class**: `App\Events\CompartmentOpenStatusUpdated`
- **Door state (from MQTT snapshots and persistence)**
  - **Broadcast event name**: `.compartment.door_state.updated`
  - **Server event class**: `App\Events\CompartmentDoorStateUpdated`

### 3.3 Payloads

**Open status** (`.compartment.open.status.updated`):

```json
{
  "command_id": "uuid",
  "compartment_id": "uuid",
  "status": "accepted|denied|sent|acknowledged|opened|already_open|door_jammed|failed",
  "error_code": "nullable-string",
  "message": "nullable-string",
  "compartment_number": "nullable-integer",
  "locker_name": "nullable-string"
}
```

`compartment_number` and `locker_name` provide human-readable context for
terminal notifications. They can be `null` when the compartment read model is
unavailable. For denied requests, `message` contains a stable reason code such
as `unverified_email` or `missing_active_access`; clients must localize known
codes and use a generic denial message for unknown values. See ADR-0046.

**Door state** (`.compartment.door_state.updated`):

```json
{
  "compartment_id": "uuid",
  "door_state": "open|closed|unknown",
  "door_state_changed_at": "nullable-ISO8601-string"
}
```

## 4) Recommended Client Flow

1. Call `POST /api/compartments/{id}/open`.
2. Use returned `command_id` as correlation ID in client state.
3. Subscribe via Echo to `users.{userId}.compartment-status`.
4. Handle `.compartment.open.status.updated` events for this `command_id`.
5. Handle `.compartment.door_state.updated` for live door state when implemented in the client.
6. If websocket is unavailable, poll
   `GET /api/compartments/open-requests/{commandId}` until final state.

Final states:

- `opened` (the door was observed physically open)
- `already_open` (the door was already open before the unlock pulse — a deviation,
  not a plain success)
- `door_jammed` (the pulse was sent but the door never opened: jam, blockage, worn
  latch, or a failed sensor)
- `failed` (device/hardware failure executing the command)
- `denied` (authorization denied)

Intermediate states:

- `accepted`
- `sent`
- `acknowledged` (the unlock pulse was sent; the door has not been checked yet)

> **`opened` changed meaning in ADR-0040.** It previously meant the unlock pulse
> had been sent, which is now `acknowledged`. A client that treats `acknowledged`
> as success will report doors as open that never moved. Expect `acknowledged`
> first and a physical outcome after it, up to the locker bank's
> `heartbeat_interval_seconds`. Rows written before ADR-0040 carry the old
> meaning and are not reinterpreted.

## 5) Frontend Requirements

- Configure Laravel Echo with Reverb connection settings.
- Ensure authenticated requests for private channel subscription.
- Keep command status UI keyed by `command_id`.
- Implement reconnect + polling fallback behavior.
