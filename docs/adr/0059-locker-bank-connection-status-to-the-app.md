# ADR-0059: Locker bank connection status reaches the app in realtime

## Status

Accepted

## Date

2026-09-16

## Context

The mobile app colours each locker bank chip online or offline. Until now that
colour came from `getFakeLockerStatus()`, which summed the character codes of the
bank's UUID and called it offline when the total divided by three. The indicator
was deterministic nonsense: a working bank could show red forever because of its
identifier, and a dead one green.

The backend already knows the truth, and has for some time:

- `LockerHeartbeatHandler` records `LockerConnectionRestored` when a heartbeat
  arrives from a bank that was not online.
- `locker:detect-offline`, scheduled every minute, records
  `LockerConnectionLost` for banks past their heartbeat timeout.
- `LockerBankProjector` builds `connection_status` from those events.
- `GET /api/locker-banks/{lockerBank}/status` exposes it per bank.

What was missing was a path from that column to the app that does not cost one
request per bank per render, and a way for the value to change while the user is
looking at it.

Compartment door state and content notes already solve the same problem:
a storable event, a queued reactor resolving an audience, a `ShouldBroadcastNow`
event fanned out to per-user private channels, and an app handler patching the
`getCompartmentsAccessible` cache in place. This decision follows that path
rather than introducing another.

## Decision

**1. The compartment list carries each bank's `connection_status`.**

`AccessibleCompartmentsResource` includes `connection_status` per locker bank, so
the first paint is correct from the request the app already makes. The heartbeat
timestamps stay on the dedicated status endpoint: the app does not render them,
and Scramble types them as non-nullable when they are not.

**2. Connection changes broadcast on their own channel, `users.{id}.locker-banks`.**

Per [ADR-0056](0056-account-state-on-its-own-realtime-channel.md), a channel is
named after what it carries. A locker bank is not a compartment, so its
connectivity does not go on `users.{id}.compartment-status`. The channel is
authorised exactly as the other two are, and served by the app's single Echo
instance — one more subscription, not one more websocket.

`LockerBankConnectionUpdated` is emitted by a reactor on
`LockerConnectionLost`, `LockerConnectionRestored`, and `LockerProvisioningReset`,
fanned out to the users who hold access to any compartment in that bank plus the
roles permitted to open compartments — the same audience rule the per-compartment
broadcasts use, now sharing one query.

The app patches `connection_status` into the cached bank entry, the way it
already patches door state.

**3. `unknown` is a state, not a synonym for offline.**

The column defaults to `'unknown'`, meaning the bank has never reported. The app
renders that in its own neutral palette. Showing it as offline would assert a
failure nobody has observed — the same class of lie the fake told.

**4. A heartbeat from any non-online state brings a bank online.**

`LockerHeartbeatHandler` previously recorded `LockerConnectionRestored` only when
the bank was `offline`. Since nothing else writes `'online'`, a freshly
provisioned bank that heartbeated perfectly stayed `unknown` forever — it had
never timed out, so nothing ever recorded that it was up. The transition is now
"was not online".

## Rationale

The realtime path is the fast path, not the source of truth. The list request
carries the status too, so a user whose socket is down, or who cold-starts the
app, still sees the right colour; a missed event costs latency, not correctness.
That is the same division ADR-0056 drew for terms acceptance.

Going offline cannot be realtime in the strict sense. Nothing observes an
absence, so the value lags by up to the heartbeat timeout while the sweep runs.
Broadcasting removes the refetch, not that floor. Coming back online is
immediate, because a heartbeat is a message that arrives.

Reusing the compartment audience rule matters more than it looks: a bank's
connectivity is only interesting to someone who can open something inside it, and
that is precisely the set the per-compartment broadcasts already compute.

## Alternatives Considered

### Alternative A: Broadcast on `users.{id}.compartment-status`

- Pros: no new channel, no new authorisation, no new subscription.
- Cons: the channel name stops describing its contents, which is the cost
  ADR-0056 already refused to pay for account state.
- Why not chosen: consistency with the existing decision is worth more than the
  few lines saved, and the argument for an exception would have to be made again
  by every future event.

### Alternative B: Per-bank polling of `GET /locker-banks/{id}/status`

- Pros: no new event, no new field; the endpoint exists today.
- Cons: one request per bank per refresh, and the indicator is only as fresh as
  the poll interval.
- Why not chosen: the list request already returns the banks; adding a field to
  it costs nothing and is correct on first paint.

### Alternative C: Rely on the broadcast alone, without the list field

- Pros: smaller API change.
- Cons: the first paint would have no status, and a user who never receives an
  event would never see one.
- Why not chosen: the broadcast is the fast path, not the guarantee.

### Alternative D: Treat `unknown` as offline in the app

- Pros: two states instead of three; no new palette entry.
- Cons: tells the user a bank has failed when nothing has been observed about it.
- Why not chosen: that is the fake's defect in a quieter form.

## Consequences

### Positive

- The indicator reflects the system rather than a hash of an identifier.
- A bank going offline recolours without a refetch, on a socket already open.
- Decision 4 also fixes the admin panel and the Filament group headings, which
  read the same column and had the same "never leaves unknown" problem.
- Provisioning reset now reaches the app, where previously the projector's direct
  write was invisible to any subscriber.

### Negative

- A third channel to authorise, subscribe to, and leave on teardown.
- A bank's status is now readable two ways — the dedicated endpoint and the list
  field. The endpoint remains the detailed view, carrying heartbeat timings the
  list deliberately omits.
- A third state means a third palette entry and a colour users must learn.

### Risks

- **Offline is late by design.** Up to the heartbeat timeout plus the sweep's
  one-minute cadence. A user may tap a compartment on a bank that is already
  dead. Mitigation: the open request fails on its own path and reports why.
- **Fan-out cost.** A bank with many access holders broadcasts to each of them on
  every transition. Transitions are rare, and the audience query is the one
  already used per compartment.
- **Scheduler dependency.** If the `scheduler` service is not running, banks
  never go offline at all. That is pre-existing, and is why the status endpoint
  also exposes `last_heartbeat_at` for operators.

## Rollout / Migration

No migration. The column, its events, and the projector already exist; this adds
a response field, a channel, a broadcast, and the app's use of them.

The mobile client must be regenerated against a running backend
(`pnpm run generate:api`) for the new field to appear in
`GetCompartmentsAccessibleApiResponse`.

Fallback: removing the `.listen()` leaves the app correct but only as fresh as
its refetches.

## Supersedes / Superseded By

- Supersedes: none.
- Related: [ADR-0056](0056-account-state-on-its-own-realtime-channel.md) — this
  applies its channel-naming rule rather than arguing an exception to it.
- Related: [ADR-0029](0029-realtime-compartment-content-note.md) and the
  door-state broadcasts, whose audience rule and cache-patching pattern this
  follows.

## References

- Related issues: #251
- Related code: `app/Reactors/LockerBankConnectionBroadcastReactor.php`,
  `app/Events/LockerBankConnectionUpdated.php`,
  `app/Mqtt/Handlers/LockerHeartbeatHandler.php`,
  `app/Console/Commands/DetectOfflineLockers.php`,
  `routes/channels.php`,
  `mobile-app/src/features/realtime/applyBankConnection.ts`
