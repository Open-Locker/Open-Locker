# ADR-0003: Public web password reset fallback

## Status

Accepted

## Date

2026-03-01

## Context

Password reset emails are sent to all users, but the application currently has
no public browser page for the reset flow. The mobile app already exposes a
reset-password screen, but email clients and desktop devices may open the reset
link in a browser instead of the app. This caused the user-facing reset link to
return `404`.

The system also has a Filament admin panel with built-in auth pages, but that
panel is restricted to admins and is not a suitable default reset flow for
regular users.

## Decision

Introduce a public web password reset page at `GET /reset-password` and submit
the form to the existing backend reset logic.

- the reset email browser link resolves to `APP_URL + /reset-password`
- the page is publicly accessible and does not require admin access
- the form uses the existing reset token, email, and password validation rules
- the actual password reset continues to be handled by the shared backend reset
  implementation
- after a successful web reset, the user sees a confirmation message and is
  instructed to sign in again in the app

## Rationale

This is the smallest safe change that fixes the broken reset flow for all
users. It avoids coupling end-user authentication to the admin-only Filament
panel and reuses the existing backend password reset logic instead of creating a
second reset implementation.

## Alternatives Considered

### Alternative A: Use Filament password reset pages as the default

- Pros:
  - ready-made UI and auth flow
  - consistent admin design
- Cons:
  - wrong access model for regular users
  - creates confusion between admin and end-user auth flows
- Why not chosen:
  - normal users must not depend on admin-only access paths

### Alternative B: App-only reset flow via deep links

- Pros:
  - minimal backend UI work
  - keeps the primary UX inside the app
- Cons:
  - fragile in desktop and browser contexts
  - fails when app linking is unavailable or the app is not installed
- Why not chosen:
  - password reset must remain reachable outside the app as a reliable fallback

## Consequences

### Positive

- password reset links work in browsers and desktop email clients
- regular users no longer depend on Filament auth routes
- backend keeps a single source of truth for reset validation and persistence

### Negative

- the backend now serves one additional public HTML page
- short-term styling is only Filament-inspired, not a full shared auth surface

### Risks

- public auth pages require careful regression testing; mitigate with feature
  tests for both HTML and API reset flows
- long-term UX may diverge from future web auth decisions; mitigate with a
  follow-up backlog item for a consolidated web auth flow

## Rollout / Migration

1. Expose `GET /reset-password` publicly.
2. Reuse the existing reset handler for both web and API submissions.
3. Keep the reset email browser link derived from `APP_URL`.
4. Add feature tests for the public page and web reset submission.
5. Plan a follow-up to consolidate the long-term web auth/reset experience.

Fallback: if issues appear, disable the public page and revert to the previous
link behavior while investigating.

## Supersedes / Superseded By

- Supersedes: N/A
- Superseded by: N/A

## References

- Related PRs: N/A
- Related issues:
  - `#63`
- Related docs:
  - `docs/adr/0000-template.md`
