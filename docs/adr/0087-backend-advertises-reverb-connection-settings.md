# ADR-0087: The backend advertises its Reverb connection settings

## Status

Proposed

## Date

2026-11-03

## Context

The mobile app lets users switch the API server at runtime (**Change server**).
ADR-0023 configured the Reverb socket from build-time `EXPO_PUBLIC_REVERB_*`
variables, and the production and store builds set them to open-locker.cloud.

A store build pointed at a self-hosted backend therefore keeps connecting to
open-locker.cloud's Reverb. That server cannot authorize the self-hosted users'
private channels, so door state, bank connection and notes go stale until the
user pulls to refresh. The socket can still report `connected`, so the "Live
updates paused" notice does not appear (issue #353).

## Decision

`GET /api/identify` gains a `realtime` field with the server's public Reverb
connection settings: `{ key, host, port, scheme }`. It is `null` when the server
has no public Reverb host or key configured.

The values come from the configuration the Filament panel's Echo client already
uses (`config('filament.broadcasting.echo')`, fed by `VITE_REVERB_*`), so a
self-hoster configures the public socket address once. The key is public by
design; `REVERB_APP_SECRET` never leaves the server.

The mobile app reads `/identify` before it opens the socket and uses only the
advertised settings. The build-time `EXPO_PUBLIC_REVERB_*` variables and the
values derived from the API URL are removed. If the server advertises nothing,
or `/identify` fails, the app opens no socket and shows live updates as paused;
REST and pull-to-refresh keep working. This supersedes item 2 ("Connection
config") of ADR-0023; the rest of ADR-0023 stands.

## Rationale

- `/identify` is unauthenticated and already the app's "is this an Open-Locker
  server" check, so it needs no new route or extra request.
- Reading it on every connect, through the RTK Query cache, follows server
  switches and server-side config changes without a separate persistence layer.
- No fallback: a build-time default points at one particular server, so falling
  back to it sends a self-hoster's users and channel names to a server that
  cannot authorize them, and can look connected while receiving nothing. Saying
  "paused" is honest; guessing is not.

## Alternatives Considered

### Alternative A: A dedicated endpoint (e.g. `/api/realtime-config`)

- Pros: keeps `/identify` minimal.
- Cons: one more round trip and route for four public values.
- Why not chosen: `/identify` already serves "what is this server".

### Alternative B: Persist the settings only on server switch

- Pros: no request before connecting.
- Cons: misses Reverb changes on the server; needs its own storage and migration
  for users who switched before this change.
- Why not chosen: the identify response is small and usually already cached by
  the sign-in screen.

### Alternative C: Keep `EXPO_PUBLIC_REVERB_*` as a fallback

- Pros: live updates keep working against backends that predate this ADR.
- Cons: the fallback is the open-locker.cloud socket, which silently fails for
  any other backend; two sources of truth for one setting.
- Why not chosen: see Rationale. The only backend the store build targets by
  default is open-locker.cloud, which is deployed together with this change.

### Alternative D: Let users type the Reverb host in the app

- Pros: no backend change.
- Cons: users must know four infrastructure values; easy to get wrong.
- Why not chosen: the server already knows them.

## Consequences

### Positive

- A store build works with live updates against any backend that sets
  `VITE_REVERB_*`.
- No Reverb settings in the app, `.env` or EAS; the backend is the one source.

### Negative

- The socket opens only after `/identify` answers, adding one request on cold
  start when it is not cached.
- A backend older than this change gets no live updates from new app builds
  until it is upgraded.
- The local Docker stack advertises `localhost`, which the admin panel in the
  browser needs. Android dev builds reach it through `adb reverse`; before this
  change they derived `10.0.2.2` from the API URL.

### Out of scope

- Issue #353 also asks whether "Live updates paused" should cover a failed
  channel subscription, not only connection states. This ADR leaves that to a
  follow-up: the notice still reflects only the connection state.

### Risks

- A backend with a wrong `VITE_REVERB_*` value now breaks mobile live updates,
  not only the admin panel's. That makes the misconfiguration visible in both.

## Rollout / Migration

Additive API change. Deploy the backend (open-locker.cloud must have
`VITE_REVERB_*` set, which the Coolify compose file already requires) before
releasing an app build with this change. Remove the `EXPO_PUBLIC_REVERB_KEY`
EAS secret afterwards.

## Supersedes / Superseded By

- Supersedes: ADR-0023, item 2 "Connection config" only
- Superseded by: —

## References

- Related issues: #353, #343, #270
- Related docs: `docs/adr/0023-mobile-realtime-compartment-status-via-reverb.md`
