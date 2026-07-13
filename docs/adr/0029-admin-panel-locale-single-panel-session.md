# ADR-0029: Admin panel locale via single panel + session-stored locale

## Status

Accepted

## Date

2026-07-13

## Context

ADR-0024 (admin-panel extension) localized the Filament admin panel by mounting
one panel per locale under a URL prefix (`/en/admin`, `/de/admin`), with a
`SetPanelLocale` middleware reading the locale from URL segment 1 and a
hand-rolled `EN | DE` switcher injected via render hooks.

Two things prompted revisiting it:

1. **The URL-rewriting switcher is broken in practice** (#154): clicking the
   non-active locale link does not reliably switch the UI language, and the
   dual-panel setup had no automated test coverage.
2. **The main argument for URL-prefixed locales carries little weight here.**
   Shareable, language-stable URLs were the rationale, but the admin panel sits
   entirely behind authentication: there is no SEO concern, links are rarely
   shared between operators, and when they are, rendering in the *viewer's* own
   locale is arguably the better behavior.

The dual-panel design also carried ongoing costs: every panel-level change had
to stay identical across two panel providers, `/admin` needed a redirect shim,
and the switcher had to rewrite URL prefixes.

Issue #155 proposed moving to a single panel with the community
`bezhansalleh/filament-language-switch` plugin (whose 5.x line now supports
Filament v5, unlike when ADR-0024 rejected it). During implementation the team
decided to keep the established inline `EN | DE` switcher look rather than the
plugin's dropdown trigger — at which point the plugin would have contributed
only ~20 lines of session/cookie glue, so it was dropped in favor of owning
that glue directly (ADR-0024's "Alternative E", session-stored locale).

This supersedes only the **admin-panel section** of ADR-0024. The API locale
mechanism (`Accept-Language` header, `SetLocale` middleware) is unchanged and
remains governed by ADR-0024.

## Decision

Use a **single Filament panel** at `/admin` with a **session-stored locale**,
no plugin:

- The inline `EN | DE` switcher (`locale-switcher.blade.php`, user-menu and
  pre-auth render hooks as before) links to a small named route
  (`locale.switch`) that validates the locale against
  `config('app.supported_locales')`, stores it in the session plus a long-lived
  cookie (so the choice survives session expiry), and redirects back.
- The `SetPanelLocale` panel middleware is repurposed: instead of reading URL
  segment 1, it applies the stored locale (session, then cookie); when nothing
  is stored it negotiates from the browser's `Accept-Language`, falling back to
  the first supported locale.
- `AdminDePanelProvider` is removed. The panel moves back from `/en/admin` to
  `/admin`; old `/{en|de}/admin/...` URLs 301-redirect to the equivalent
  `/admin/...` path.

The **lazy-label discipline from ADR-0024 stays mandatory**: the session locale
is still applied by request middleware after panel boot, so static label
properties would render in the boot-time locale. Navigation groups keep their
closures and resources/pages keep their `get*Label()` overrides.

## Rationale

- One panel halves the panel-wiring surface and removes the "keep both panels
  identical" consequence of ADR-0024.
- URL-stable language links provided no real value behind an auth wall.
- The entire locale mechanism is ~30 lines of owned code (middleware + route +
  Blade view) with feature-test coverage — a dependency would not pull its
  weight for that, especially with its switcher UI unused.

## Alternatives Considered

### Alternative A: Fix the custom switcher in the dual-panel setup (#154)

- Pros: No behavior change; keeps language-stable URLs.
- Cons: Keeps two panel providers, the redirect shim, and the URL-rewrite
  mechanics that were the fragile part in the first place.
- Why not chosen: Fixing code invested into a design we no longer want.

### Alternative B: `bezhansalleh/filament-language-switch` plugin (issue #155's proposal)

- Pros: Community-standard; maintained switcher UI, session/cookie persistence
  and locale-negotiation middleware out of the box.
- Cons: Its stock trigger is a dropdown/modal button and cannot be configured
  into the team's preferred inline `EN | DE` links — that requires publishing
  and rewriting its views (fragile vendor-view coupling) or hiding its UI
  entirely. With the UI unused, the dependency provides only trivial glue; it
  also requires a custom Filament theme (Vite-built) just for its styles.
- Why not chosen: The team kept the inline switcher look, leaving the plugin
  responsible for ~20 lines of code we can own without a dependency. It was
  installed, wired up, and then removed within this change.

## Consequences

### Positive

- Locale switching works, including pre-auth pages, with feature-test coverage.
- Single panel provider; less wiring, no duplicated panel config, no new
  dependency, no custom theme/asset-build requirement.
- Locale list shared with the API via `config('app.supported_locales')`.
- First-visit language follows the browser's `Accept-Language`, consistent with
  the API's negotiation model.

### Negative

- Admin URLs are no longer language-stable: a shared `/admin/...` link renders
  in the viewer's stored locale, not the sender's.
- Bookmarks to `/en/admin/...` or `/de/admin/...` rely on the 301 redirect
  route staying in place.
- The switcher UI and locale mechanics remain owned code (small, but ours).

### Risks

- Static label properties silently render in the boot-time locale (same pitfall
  as ADR-0024); mitigated by the established `get*Label()` convention and the
  locale-switch feature tests.

## Supersedes / Superseded By

- Supersedes: ADR-0024 (admin-panel section only; the API `Accept-Language`
  mechanism remains in force)
- Superseded by: none

## References

- Related issues: #154, #155
- Related docs: `docs/adr/0024-api-localization-via-request-locale.md`,
  `locker-backend/app/Providers/Filament/AdminPanelProvider.php`,
  `locker-backend/app/Http/Middleware/SetPanelLocale.php`,
  `locker-backend/resources/views/filament/locale-switcher.blade.php`,
  `locker-backend/tests/Feature/AdminPanelLocaleSwitchTest.php`
