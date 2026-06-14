# ADR-0022: Mobile realtime compartment status via Reverb (Laravel Echo client)

## Status

Proposed

## Date

2026-06-14

## Context

The backend already broadcasts compartment updates. ADR-0016 chose a single
private channel `users.{userId}.compartment-status` carrying both open-command
progress (`CompartmentOpenStatusUpdated`) and door-state changes
(`CompartmentDoorStateUpdated`), and ADR-0020 added
`CompartmentStatusBroadcastService::recipientUserIdsForCompartment()` to resolve
recipients. `routes/channels.php` authorizes the channel for the matching user,
and `BROADCAST_CONNECTION=reverb` with `laravel/reverb` is installed. The
door-state event (`broadcastAs: compartment.door_state.updated`) ships
`{ compartment_id, door_state, door_state_changed_at }`.

ADR-0016 stops at the backend boundary. It states that "mobile and admin clients
should subscribe to `users.{userId}.compartment-status`" but does **not** decide
*how* a React Native (Expo) client does so. Today the mobile app has **no
realtime client at all** (no `laravel-echo`, no `pusher-js`, no socket code); it
only shows `door_state` from `GET /api/compartments/accessible` on load, so the
UI goes stale after a door opens until the screen is reloaded (issue #45).

Constraints / assumptions for the client:
- The app authenticates with a bearer token kept in the Redux `auth` slice and
  persisted via `expo-secure-store`; API calls attach `Authorization: Bearer …`
  (`src/store/baseApi.ts`).
- Compartment data is served by RTK Query (`getCompartmentsAccessible`).
- We prefer to stay within Expo's managed flow (no custom native modules).
- Command feedback (open accepted/succeeded) and physical door state must remain
  distinct (issue #45).

## Decision

Implement the **client** side of the existing broadcast contract as follows.

1. **Transport**: add `laravel-echo` + `pusher-js` (both pure JS, no native
   module) and connect to Reverb, which speaks the Pusher protocol. Echo is
   configured with `broadcaster: 'reverb'`.
2. **Connection config**: read Reverb coordinates from
   `EXPO_PUBLIC_REVERB_KEY/HOST/PORT/SCHEME` (typed in `process-env.d.ts`),
   matching the backend `REVERB_*` values clients use.
3. **Private-channel auth**: use a custom Echo authorizer that POSTs to the
   backend `/broadcasting/auth` with the same `Bearer <token>` the API uses. The
   backend `/broadcasting/auth` endpoint must authenticate via the **token guard
   (sanctum/api)**, not a web session.
4. **Subscription + cache update**: subscribe to
   `private-users.{userId}.compartment-status`, listen for
   `.compartment.door_state.updated`, and patch the RTK Query cache in place via
   `generatedApi.util.updateQueryData('getCompartmentsAccessible', …)` —
   matching the compartment by `compartment_id` and setting `door_state` /
   `door_state_changed_at`. The card and detail sheet re-render without a
   refetch. The API response remains the source of truth on initial load.
5. **Command vs. state separation**: `door_state` is sourced only from the API
   and `.compartment.door_state.updated`. Open-command feedback
   (pending/success/failure) stays on the existing open-request path and is
   **not** derived from door-state events.
6. **Fallback**: when realtime is unavailable, refresh `getCompartmentsAccessible`
   — on `AppState` returning to `active`, and when the socket reports
   disconnected/unavailable — so the UI converges to the latest known state
   without manual refresh.
7. **Lifecycle**: a single Echo instance is created after authentication and torn
   down on logout; the subscription is mounted beneath the Redux provider so it
   can read the token/user id and dispatch cache updates.

The client uses the current `compartment-status` / `compartment.door_state.updated`
naming only; the legacy `compartment-open` wording (already deprecated by
ADR-0016) is not introduced.

## Rationale

- `laravel-echo` + `pusher-js` is the officially documented Reverb client and is
  pure JavaScript, so it bundles through Metro with no `expo prebuild`, config
  plugin, or native linking — the lowest-friction option for this Expo app.
- Patching the RTK cache (vs. invalidating/refetching on every event) gives an
  instant UI update and avoids a network round-trip per door change; refetch is
  kept only as the fallback path.
- Reusing the existing bearer token for channel auth avoids a second auth scheme
  and keeps a single source of identity.
- Treating the API as source-of-truth with a realtime overlay plus refetch
  fallback keeps the screen correct even when the socket is down — realtime is an
  optimization, not a correctness dependency.

## Alternatives Considered

### Alternative A: Polling / refetch only (no realtime)

- Pros: no socket lifecycle, no new dependency, no channel-auth wiring.
- Cons: visible latency after a door opens; wasted requests; worse battery.
- Why not chosen: issue #45 requires the card/sheet to update without manual
  refresh; polling alone cannot meet the "instant" goal cheaply.

### Alternative B: Raw `WebSocket` / `socket.io` client

- Pros: no Echo abstraction.
- Cons: Reverb speaks the Pusher protocol, so a raw client would re-implement
  framing, channel subscription, and `/broadcasting/auth` handshake by hand.
- Why not chosen: re-implements exactly what `laravel-echo` + `pusher-js`
  already provide and the backend already targets.

### Alternative C: Refetch the whole query on every event (no cache patch)

- Pros: trivial; no per-field merge logic.
- Cons: a network round-trip for every door change, multiplied across recipients.
- Why not chosen: the event payload already carries the changed fields; an
  in-place patch is cheaper. (Full refetch is retained as the fallback.)

## Consequences

### Positive

- Compartment cards and the detail sheet update the instant the backend
  broadcasts, satisfying #45 without manual refresh.
- Reuses the already-decided broadcast contract (ADR-0016/0020) and the existing
  token auth; no backend contract change for the happy path.
- No native code; stays inside the Expo managed flow.

### Negative

- Two new runtime dependencies (`laravel-echo`, `pusher-js`) and a socket
  lifecycle the app must manage (connect, reconnect, tear down on logout).
- Tests must mock Echo so the suite never opens a real socket.

### Risks

- **`/broadcasting/auth` guard**: if it defaults to the web/session guard, the
  RN bearer-token handshake fails even though the contract is correct. *Mitigation:
  verify/force the token (sanctum/api) guard before building the client; this is
  the first thing to test.*
- **Reverb reachability**: the app connects directly to Reverb (not via `/api`);
  it must be running and reachable in dev and prod, and `EXPO_PUBLIC_REVERB_*`
  must be correct. *Mitigation: confirm `reverb:start` and the env before wiring.*
- **User id for the channel**: the channel name needs the authenticated user's
  numeric id; the app must expose it. *Mitigation: confirm the auth state / a
  `/me`-style source provides it; otherwise add it.*
- **Reconnect / missed events**: events sent while disconnected are not replayed.
  *Mitigation: the `AppState`/disconnect refetch fallback reconciles state on
  reconnect.*

## Rollout / Migration

1. Verify prerequisites: `reverb:start` is running and `/broadcasting/auth`
   authenticates via the token guard; confirm the app can read its user id.
2. Add `EXPO_PUBLIC_REVERB_*` env + `process-env.d.ts` types.
3. Add `laravel-echo` + `pusher-js`; create the Echo client with the
   bearer-token authorizer.
4. Add the subscription hook: listen for `.compartment.door_state.updated` and
   patch `getCompartmentsAccessible`.
5. Add the `AppState` / disconnect refetch fallback.
6. Mock Echo in Jest; add tests for the cache-patch reducer path.

## Supersedes / Superseded By

- Supersedes: none.
- Superseded by: none.
- Builds on: ADR-0016 (broadcast channel/events), ADR-0020 (recipient
  resolution).

## References

- Related issues:
  - [#45](https://github.com/Open-Locker/Open-Locker/issues/45)
- Related ADRs:
  - `docs/adr/0016-retained-compartment-snapshot-and-door-state-persistence.md`
  - `docs/adr/0020-group-based-compartment-access.md`
  - `docs/adr/0018-codegen-from-live-openapi-url.md`
- Related code:
  - `locker-backend/app/Events/CompartmentDoorStateUpdated.php`
  - `locker-backend/routes/channels.php`
  - `mobile-app/src/store/baseApi.ts`, `mobile-app/src/store/generatedApi.ts`
