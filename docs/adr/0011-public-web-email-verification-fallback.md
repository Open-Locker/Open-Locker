# ADR-0011: Public web email verification fallback

## Status

Accepted

## Date

2026-04-11

## Context

Email verification mails are sent to end users, but the existing verification
link resolves directly to the authenticated API endpoint
`GET /api/verify-email/{id}/{hash}`.

This backend is API-first and has no public login page for regular users. When
the mail link is opened in a browser without an active authenticated session,
Laravel's auth middleware attempts to redirect the guest to a `login` route.
That route does not exist for end users, which causes a server error instead of
completing or gracefully handling email verification.

The project already made the same class of decision for password reset in
`ADR-0003`, where a public browser-safe fallback was added because email links
are frequently opened outside the app context.

## Decision

Introduce a public signed web verification route at
`GET /verify-email/{id}/{hash}` and make email verification mails use that
browser-safe route as their primary action link.

- the public route remains signed and throttled
- the controller verifies the email hash against the addressed user before
  marking the email as verified
- the existing authenticated API verification route remains available for app
  clients and internal API use
- the backend renders a lightweight confirmation page for browser-based
  verification instead of relying on a nonexistent login page
- unauthenticated requests to `/api/*` routes return a JSON `401` instead of
  redirecting to `route('login')`

## Rationale

This is the smallest safe change that fixes the broken user-facing verification
flow while preserving the existing API contract for the mobile app. It matches
the already accepted password reset strategy: email links must remain usable in
normal desktop and browser contexts, not only inside an authenticated app
session.

## Alternatives Considered

### Alternative A: Keep the API verification link and only change unauthenticated handling

- Pros:
  - minimal backend changes
  - no extra public page
- Cons:
  - browser users still land on a raw API response
  - poor UX for the primary email click path
- Why not chosen:
  - it removes the crash but does not provide an appropriate browser fallback

### Alternative B: Require users to verify only inside the app

- Pros:
  - no public verification page
  - keeps the flow fully app-centric
- Cons:
  - fragile when email clients open links in browsers
  - fails for desktop users and devices without app deep linking
- Why not chosen:
  - email verification must remain reachable in ordinary browser contexts

## Consequences

### Positive

- verification emails now work reliably from desktop and browser email clients
- the existing app/API verification endpoint stays intact
- API-first guest handling is more robust because `/api/*` requests no longer
  depend on a web `login` route

### Negative

- the backend now serves one additional public HTML page
- verification mail delivery uses a custom notification instead of Laravel's
  default notification class

### Risks

- public auth links require careful signature validation; mitigate by keeping
  signed middleware and explicit hash checks
- browser and API verification flows can diverge over time; mitigate with
  feature tests that cover both paths

## Rollout / Migration

1. Add the public signed verification route and confirmation page.
2. Switch verification mails to the browser-safe route.
3. Keep the existing API verification endpoint for app clients.
4. Add feature tests for the new public flow and unauthenticated API handling.
5. If issues appear, revert the notification link target while investigating.

## Supersedes / Superseded By

- Supersedes: N/A
- Superseded by: N/A

## References

- Related PRs: N/A
- Related issues: N/A
- Related docs:
  - `docs/adr/0000-template.md`
  - `docs/adr/0003-public-web-password-reset-fallback.md`
