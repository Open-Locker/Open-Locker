# ADR-0023: Realtime compartment content-note updates via Reverb

## Status

Proposed

## Date

2026-06-24

## Context

ADR-0022 implemented the mobile client side of the realtime compartment
contract: a single private channel `users.{userId}.compartment-status` carrying
door-state changes (`.compartment.door_state.updated`), consumed by the Expo app
via a Laravel Echo client that patches the `getCompartmentsAccessible` RTK Query
cache in place. ADR-0016 defined the channel and ADR-0020 added
`CompartmentStatusBroadcastService::recipientUserIdsForCompartment()` for
recipient resolution.

The compartment **content note** (issue #116, commit `64303cb`) is an
event-sourced field: editing it records a `CompartmentContentNoteUpdated`
storable event, a projector writes `content_note` /
`content_note_updated_at` / `content_note_updated_by_user_id` to the read model,
and those fields ship in `GET /api/compartments/accessible`. Unlike door-state,
the note lives **only** in the database — there is no MQTT/Modbus/hardware path.

Today the note is shown only on load. After a user edits it, other clients (and
the editor's own list view, which reads a separate cache from the edit screen)
keep showing the previous value until the screen is reloaded — the same staleness
problem #45 solved for door-state.

## Decision

Reuse the ADR-0022 pipeline end-to-end; add a second event on the **same**
channel rather than a new transport.

1. **Backend broadcast event** `App\Events\CompartmentNoteUpdated`
   (`ShouldBroadcastNow`), broadcast on `users.{userId}.compartment-status` with
   `broadcastAs: compartment.content_note.updated` and payload
   `{ compartment_id, content_note, content_note_updated_at,
   content_note_updated_by_user_id }` — matching the REST resource shape so the
   cache patch and initial load are interchangeable. (Named `CompartmentNoteUpdated`
   to avoid colliding with the storable event `CompartmentContentNoteUpdated`.)
2. **Backend reactor** `CompartmentContentNoteBroadcastReactor` reacts to the
   `CompartmentContentNoteUpdated` storable event, resolves recipients via the
   existing `CompartmentStatusBroadcastService`, and fires the broadcast — keeping
   the push a side-effect of the stored fact, mirroring
   `CompartmentDoorStateBroadcastReactor`.
3. **Mobile** adds an `applyContentNote` cache patcher (sibling of
   `applyDoorState`) and a second `.listen('.compartment.content_note.updated')`
   on the already-subscribed channel. The existing `AppState`/disconnect REST
   refetch fallback covers missed note events for free.

No API response shape changes, so **no mobile codegen run is required**; the note
fields already exist on the generated `AccessibleCompartments` type.

## Alternatives Considered

### Alternative A: Refetch `getCompartmentsAccessible` on note change

- Pros: no new event/patcher.
- Cons: a network round-trip per edit across all recipients; same objection
  ADR-0022 raised for door-state.
- Why not chosen: the event already carries the changed fields; an in-place patch
  is cheaper and instant. Full refetch is retained only as the fallback.

### Alternative B: A separate channel/transport for notes

- Pros: isolates note traffic.
- Cons: a second subscription, auth handshake, and lifecycle for no benefit; the
  recipient set is identical to door-state.
- Why not chosen: the existing channel already targets exactly these recipients.

## Consequences

### Positive

- The note updates instantly across clients without a reload, satisfying the
  request; reuses ADR-0016/0020/0022 with no new dependency or transport.
- Simpler than door-state: no hardware path, so the note is authoritative the
  moment the event is stored.

### Negative

- A second event type on the channel; consumers must distinguish by event name
  (already the case).

### Risks

- **Missed events while backgrounded**: same as door-state; mitigated by the
  existing `AppState`/disconnect refetch fallback.

## References

- Related issues:
  - [#116](https://github.com/Open-Locker/Open-Locker/issues/116)
  - [#45](https://github.com/Open-Locker/Open-Locker/issues/45)
- Related ADRs:
  - `docs/adr/0022-mobile-realtime-compartment-status-via-reverb.md`
  - `docs/adr/0016-retained-compartment-snapshot-and-door-state-persistence.md`
  - `docs/adr/0020-group-based-compartment-access.md`
- Related code:
  - `locker-backend/app/Events/CompartmentNoteUpdated.php`
  - `locker-backend/app/Reactors/CompartmentContentNoteBroadcastReactor.php`
  - `mobile-app/src/features/realtime/applyContentNote.ts`
  - `mobile-app/src/features/realtime/useCompartmentStatusRealtime.ts`
