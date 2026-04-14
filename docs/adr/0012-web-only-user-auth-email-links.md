# ADR-0012: Web-only user auth email links

## Status

Accepted

## Date

2026-04-11

## Context

Open-Locker is an API-first system where end users are expected to use the
React Native app, not the Filament admin backend. However, some account
recovery and onboarding actions start from an email client and therefore need a
safe browser destination.

The project already provides simple public web pages for password reset and
email verification. Password reset mails still included an additional app deep
link, while verification mails now use a public web route directly. This mixed
approach creates unnecessary complexity and suggests that email clients should
open the app, even though the intended fallback for these actions is now the
public web page.

## Decision

For end-user password reset and email verification, outbound email links should
open public backend web pages only.

- password reset emails use the public `GET /reset-password` page as their only
  action link
- email verification emails use the public signed
  `GET /verify-email/{id}/{hash}` page as their action link
- end-user auth emails do not include app deep links
- end-user auth emails do not direct users to Filament admin authentication
  pages
- the mobile app remains the primary product surface after the browser-based
  recovery or verification step is complete

## Rationale

This keeps the user-facing email flow simple and predictable. Email clients
open browser URLs reliably across desktop and mobile contexts, while app deep
links are more fragile and no longer necessary for these two actions. The web
pages already exist and are intentionally minimal, which gives users a clean
completion path without coupling them to the admin backend.

## Alternatives Considered

### Alternative A: Keep hybrid email links with both app and web destinations

- Pros:
  - offers multiple possible entry points
  - can prefer the app when deep linking works
- Cons:
  - more copy and more cognitive load in emails
  - extra config and notification logic
  - inconsistent with the decision to use simple web fallback pages
- Why not chosen:
  - the extra complexity does not provide enough value for the current product
    direction

### Alternative B: Route end users through Filament auth pages

- Pros:
  - reuses an existing backend auth surface
  - avoids separate public pages
- Cons:
  - wrong product surface for normal users
  - couples end-user recovery to administrator UX
- Why not chosen:
  - the admin backend is not the intended interface for normal users

## Consequences

### Positive

- auth emails are shorter and easier to understand
- browser behavior is consistent for both password reset and email verification
- unused mobile-link configuration can be removed

### Negative

- auth emails no longer provide an app-specific shortcut
- users always complete these two steps in a browser before returning to the
  app

### Risks

- if a future native deep-link flow becomes necessary, notifications will need
  to be expanded again; mitigate by keeping the web pages as stable endpoints
- future contributors may accidentally reintroduce app-only auth mail links;
  mitigate with targeted feature tests and this ADR

## Rollout / Migration

1. Replace hybrid notifications with web-only notifications.
2. Remove unused auth-link configuration for app deep links.
3. Keep the public reset and verification pages as the browser destination.
4. Update feature tests to assert web-only mail links.

## Supersedes / Superseded By

- Supersedes: N/A
- Superseded by: N/A

## References

- Related PRs: N/A
- Related issues: N/A
- Related docs:
  - `docs/adr/0000-template.md`
  - `docs/adr/0003-public-web-password-reset-fallback.md`
  - `docs/adr/0011-public-web-email-verification-fallback.md`
