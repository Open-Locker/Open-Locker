# ADR-0056: Account-level realtime state gets its own per-user channel

## Status

Accepted

## Date

2026-08-25

## Context

[ADR-0023](0023-mobile-realtime-compartment-status-via-reverb.md) put compartment
state on a private per-user Reverb channel, `users.{id}.compartment-status`. It is
the only channel the app subscribes to.

Terms acceptance needed the same treatment. A user's profile carries
`terms_current_accepted`, the app caches it, and nothing told the app when new
terms were activated — so the prompt to accept never appeared and every write
failed with a 403 the app could not explain (#250).

The obvious shortcut was to broadcast that on the existing channel. It is already
authorised in `routes/channels.php`, the app is already subscribed, and Echo is
already connected. Adding one more `listen()` would have been a two-line change
with no new plumbing at all.

## Decision

Account-level state broadcasts on its own private channel, `users.{id}.account`,
authorised the same way as the compartment channel.

A channel is named after what it carries. `compartment-status` carrying "your
terms are out of date" makes the name a lie, and a name that has lied once stops
being read — the next unrelated event goes on whichever channel is nearest, and
the app ends up filtering a firehose to find what it cares about.

Both channels are served by **one Echo instance**. A hook per channel would open a
websocket per channel, and this event fires perhaps twice a year.

The payload is a signal, not content: `TermsAcceptanceRequired` carries a version
number and nothing else. The app invalidates its cached profile and re-reads it,
so there is exactly one answer to "must I accept" and it comes from the API.
Broadcasting the terms themselves would put a second copy on the wire, free to
disagree with the first.

Broadcasts hang off **activation**, not publication. `terms_current_accepted`
compares a user's acceptance against the *active* version, so activation is the
moment the cached profile becomes wrong. Today one service call records both, but
an activate-an-existing-version path would otherwise broadcast nothing.

## Rationale

The realtime path is not the only defence, and should not be. #250 also added a
403-triggered refresh and extended the existing reconnect/foreground fallback, so
a user whose socket is down still recovers. This channel is the fast path, not the
guarantee — which is why a missed event costs latency rather than correctness.

## Alternatives Considered

### Alternative A: Broadcast on `users.{id}.compartment-status`

- Pros: no new channel, no new authorisation, no new subscription; two lines.
- Cons: the channel name stops describing its contents, and the precedent invites
  every future event onto it.
- Why not chosen: the saving is a few lines once; the cost is paid by every reader
  afterwards.

### Alternative B: A second Echo instance for the account channel

- Pros: clean separation, each hook owning its own connection.
- Cons: a second websocket per session for an event that fires rarely.
- Why not chosen: channels are cheap, connections are not.

### Alternative C: Poll the profile instead

- Pros: no broadcast, no channel, no authorisation.
- Cons: either slow to notice or wasteful; and the fallbacks already cover the
  cases polling would.
- Why not chosen: the infrastructure for pushing this already exists.

## Consequences

### Positive

- Channel names keep describing their contents, so where a new event belongs stays
  an easy question.
- Account state can grow (verification status, role changes) without touching the
  compartment channel.
- One websocket per session, unchanged.

### Negative

- Two channel names to authorise, subscribe and keep in step across two codebases.
  Nothing fails loudly when they drift — the app simply stops hearing the event —
  so both names are pinned by tests on each side.

### Risks

- **A missed broadcast is invisible.** No acknowledgement, no retry. Mitigated by
  design: the 403 refresh and the reconnect/foreground fallback both recover the
  same state, so this path failing is a delay rather than a defect.

## Rollout / Migration

Additive. Existing clients subscribe to one channel and ignore the other; nothing
breaks if the app updates after the backend, only the live update is missing until
it does.

## Supersedes / Superseded By

- Supersedes: none.
- Related: [ADR-0023](0023-mobile-realtime-compartment-status-via-reverb.md) —
  established the per-user private channel pattern this follows.

## References

- Related issues: #250
- Code: `app/Events/TermsAcceptanceRequired.php`,
  `app/Reactors/TermsNotificationReactor.php`, `routes/channels.php`,
  `mobile-app/src/features/realtime/useCompartmentStatusRealtime.ts`
