# ADR-0068: Keep a string `name` on the login response

## Status

Accepted

## Date

2026-10-15

## Context

ADR-0020 replaced `users.name` with `first_name` and `last_name` and dropped
`name` from `POST /api/login`. Mobile builds shipped before that change still
persist the session with `response.name`.

Those builds call `expo-secure-store` with `undefined` when the field is
absent. SecureStore rejects non-strings and the sign-in screen shows:

> Invalid value provided to SecureStore. Values must be strings.

The installed app version stayed `1.0.0`, so a binary from before the name
split is not visible in the footer. Password reset succeeds; the failure is
the following sign-in, which is the first time the app stores the session.

Current builds already format `first_name` and `last_name`. They do not need
`name`, but older binaries cannot be updated by an API-only rollout unless
the field they read is a string again.

## Decision

`TokenResponse` includes `name`, set to `User::fullName()` (trimmed
`first_name` and `last_name`). The field is always a string, including `""`
when both parts are empty.

`first_name` and `last_name` stay the canonical identity fields from
ADR-0020. `name` is a display string for clients that still read it.

The current mobile app stores only string values and, when `first_name` /
`last_name` are missing, falls back to `name`. A login payload without a
token string is shown as a sign-in error instead of the SecureStore message.

## Alternatives Considered

### Alternative A: Require every tester to install a new app build

- Pros: No API change. Current source already avoids `response.name`.
- Cons: Installed binaries keep failing until each device is updated. The
  version footer does not show which binary that is.
- Why not chosen: The crash is caused by a missing response field. Restoring
  that field fixes the installed app on the next backend deploy.

### Alternative B: Drop session persistence when the display name is missing

- Pros: Avoids the SecureStore throw without an API change.
- Cons: Only helps builds that contain the new client. The reported devices
  are already installed.
- Why not chosen: It does not repair the binary that still reads `name`.

## Consequences

### Positive

- Older apps can persist a string display name and finish sign-in.
- New apps still prefer `first_name` and `last_name`.
- An empty display name is `""`, which SecureStore accepts.

### Negative

- `TokenResponse` grows a compatibility field that duplicates `fullName()`.
- Clients must not treat `name` as an editable profile field. Profile updates
  stay on `first_name` and `last_name`.

### Risks

- Removing `name` again will bring the SecureStore failure back on any binary
  that still reads it.

## References

- Issue: https://github.com/Open-Locker/Open-Locker/issues/326
- Related: [ADR-0020](0020-user-first-last-name-fields.md)
