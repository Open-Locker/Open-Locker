# ADR-0016: Retained MQTT compartment_snapshot and persisted compartment door state

## Status

Accepted

## Date

2026-04-28

## Context

Split MQTT state topics are defined in ADR-0017 (`state/heartbeat`, `state/compartments`,
`state/connection`). Earlier drafts used a multiplexed `locker/{locker_uuid}/state` topic with a
`state` discriminator; that approach was superseded. The locker-client previously published a legacy
`status_update` event on every polling interval without retain semantics. The backend did not
persist door state for API or mobile visibility. MQTT compartment status and compartment open
command progress need a single realtime channel for clients.

## Decision

1. **locker-client**: Publish compartment snapshots on `locker/{uuid}/state/compartments` per AsyncAPI
  (`state-snapshot.json`) with MQTT **retain=true**, after the first successful poll and only when
   the effective door state vector **changes**. Include **all** configured compartments each time;
   use `door_state: unknown` when Modbus data for that compartment is unavailable in the cycle.
   Remove legacy `status_update` and multiplexed `locker/{uuid}/state` payloads.
2. **locker-client**: Publish heartbeat on `locker/{uuid}/state/heartbeat` per `state-heartbeat.json`
  (`message_id`, `timestamp`, `uptime_seconds`; no top-level `state`) with **retain=false**.
3. **Backend**: Dedicated MQTT handlers per split topic (see ADR-0017). Compartment door state follows Spatie event sourcing:
  - `CompartmentDoorStateChanged` (stored when snapshot telemetry differs from the projection per compartment).
  - `CompartmentStateChangesApplied` (stored once per MQTT batch when ≥1 compartment delta exists) carries `LockerBank.last_compartment_state_change_at` — locker-wide timestamp meaning **last compartment door-state change**, not last MQTT snapshot receipt.
  - Projectors: `CompartmentProjector` (`door_state`, `door_state_changed_at`); `LockerBankProjector` (`last_compartment_state_change_at`).
  - Side-effect broadcasting via `CompartmentDoorStateBroadcastReactor` → `CompartmentDoorStateUpdated` (not MQTT handlers directly).
   Match compartments by `locker_bank_id` and compartment `number` (`compartment_number` in MQTT).
4. **Heartbeat / connection**: `LockerHeartbeatHandler` updates `last_heartbeat_at` only; offline→online transitions emit `LockerConnectionRestored` so `LockerBankProjector` updates `connection_status` (avoid duplicating writes). `LockerConnectionStateHandler` validates `state/connection` payloads and logs only until LWT product semantics are defined (heartbeat timeout remains offline detection).
5. **Inbound dedup**: Strict `message_id` deduplication via `InboundMqttProtocolGuard` for commands,
  responses, events, provisioning, heartbeat, and connection payloads; **do not** block duplicate
   `message_id` values for retained snapshots on `locker/+/state/compartments` so replay stays
   idempotent (narrow exception documented in ADR-0017).
6. **API**: Expose door state fields and `last_compartment_state_change_at` on resources used by `/compartments/accessible`.
7. **Broadcast**: Use a single private channel `users.{userId}.compartment-status` for all
  compartment-related push updates, including open-command progress (`CompartmentOpenStatusUpdated`)
   and door state changes (`CompartmentDoorStateUpdated`).

## Alternatives Considered

- **Separate channels** for open vs door state — rejected to simplify client subscriptions.
- **Dedup bypass for snapshots** — implemented narrowly for `state/compartments` only (ADR-0017).

## Consequences

- ADR-0007 remains valid for multi-board polling aggregation; its MQTT payload name `status_update`
is **superseded** by `compartment_snapshot` for the retained snapshot contract.
- Mobile and admin clients should subscribe to `users.{userId}.compartment-status` only.
- Filament and docs referencing `compartment-open` must migrate to `compartment-status`.

## References

- `docs/asyncapi/mqtt.yaml`, `docs/asyncapi/schemas/payloads/state-snapshot.json`
- `docs/adr/0007-aggregate-state-polling-across-all-configured-modbus-boards.md`
- `docs/adr/0015-define-mqtt-contract-via-asyncapi-and-json-schemas.md`
- `docs/adr/0017-split-mqtt-state-topics-by-lifecycle.md`