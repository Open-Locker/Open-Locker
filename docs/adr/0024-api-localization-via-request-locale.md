 # ADR-0024: API localization via client-supplied request locale

## Status

Accepted

## Date

2026-06-21

## Context

The backend already uses Laravel's translation helpers (`__()`, `trans()`) for
API JSON `message` strings, the public web pages (password reset, email
verification), and email notifications. Translations are keyed by their English
source string, with a single German catalog at `lang/de.json` that currently
covers only the web/email auth flows.

There is **no per-request locale negotiation**. The locale is fixed globally by
`APP_LOCALE` (today `de` in `.env`/`.env.example`), and `fallback_locale` is also
`de`. As a result:

- The app responds in one hardcoded language regardless of who is calling.
- Any `__()` key missing from `de.json` falls back to `de` (also missing) and
  renders the literal English key, so responses are an inconsistent mix.
- A German-device user and an English-device user get identical output.

The mobile app already does its own client-side UI translation
(`react-i18next` + `expo-localization`, `mobile-app/src/i18n/`) and resolves
language from the device. What it cannot translate are server-generated strings:
the public web pages and the emails Laravel sends.

This is a cross-component decision (mobile + backend) that introduces a request
convention, so it is recorded as an ADR. Tracking issue: #108.

## Decision

The client tells the backend which language it wants on **every request** via the
standard `Accept-Language` header (e.g. `Accept-Language: de`). A small HTTP
middleware reads the header, validates it against the set of supported locales,
and calls `App::setLocale()` early in the request lifecycle. Everything resolved
during that request — API `message` strings, web pages, and **emails triggered by
that request** — is rendered in the chosen language.

Laravel captures the active locale at the moment a mail/notification is dispatched
and restores it inside the queued worker, so user-initiated emails (register →
verify, forgot-password → reset) are localized correctly even though they are
queued. No stored per-user locale is required for these flows.

`fallback_locale` is set back to `en` (the source language) so any untranslated
key degrades to readable English instead of a literal key string.

Supported locales are `en` (source) and `de` (`lang/de.json`), extensible by
adding catalogs.

## Rationale

- `Accept-Language` is the idiomatic HTTP/Laravel mechanism for request-scoped
  language negotiation; it needs only one middleware and no schema change.
- Treating language as a live, per-request signal matches how the app actually
  behaves: a user may switch device/app language at any time, and the next
  request simply reflects it. There is nothing to keep in sync or let go stale.
- It fully covers the cases issue #108 asks about — API replies and the emails a
  user triggers themselves.

## Alternatives Considered

### Alternative A: Explicit `lang` query/body parameter

- Pros: Very explicit; trivial to see in logs and client code.
- Cons: Non-standard; must be threaded through every endpoint and the codegen
  client; duplicates what `Accept-Language` already expresses.
- Why not chosen: Same effect as the header but less conventional and more
  invasive to the API contract.

### Alternative B: Persisted `users.locale` column (+ `HasLocalePreference`)

- Pros: Localizes system-initiated emails where no request carries a language
  (e.g. an admin publishing new terms that mails many users).
- Cons: Stores a value that can go stale relative to the user's current device
  language; adds a migration and write path; redundant for every user-initiated
  flow.
- Why not chosen: The live request signal is the source of truth. This is
  deferred; it can be added later, scoped only to system/broadcast emails such as
  `TermsVersionPublishedNotification`, if and when those need localization.

### Alternative C: Keep the current global `APP_LOCALE`

- Pros: No work.
- Cons: One language for all clients; inconsistent mixed output due to the
  `fallback_locale=de` gap.
- Why not chosen: Does not solve the problem.

## Consequences

### Positive

- Clients control their own language with a single standard header.
- API responses and user-triggered emails are consistently localized.
- No schema change; no stale stored state.
- Untranslated keys read as English instead of raw key strings.
- The app exposes its language switch on the pre-auth screens (sign-in,
  forgot-password) as well as the account screen, so an unauthenticated user can
  pick the language that the server-rendered messages and reset/verification
  emails come back in. This follows directly from treating language as a live
  per-request signal rather than a stored user attribute.

### Negative

- System/broadcast emails not tied to a request stay in the fallback language
  until/unless Alternative B is adopted.
- Translation catalogs must be kept reasonably complete to avoid mixed-language
  output.
- Translation catalogs use proper UTF-8 umlauts (ä/ö/ü/ß), not ASCII
  transliteration (ae/oe/ue/ss); all consumers (JSON API, web pages, email) are
  UTF-8. Test assertions that hardcode German strings must match the umlaut
  spelling, so editing `lang/de.json` can require updating those tests.

### Risks

- A client omitting `Accept-Language` gets the fallback locale; mitigated by the
  app always sending the header and by `en` being a sensible fallback.
- Unsupported/malformed header values must be rejected to a supported locale;
  the middleware validates against the allow-list.

## Rollout / Migration

1. Add a `SetLocale` middleware that maps a validated `Accept-Language` value to a
   supported locale via `App::setLocale()`; register it on the API group.
2. Change `fallback_locale` to `en`; keep `APP_LOCALE` as the default for requests
   without a header.
3. Have the mobile app send `Accept-Language` (from its resolved app language) on
   all API calls, and expose the language switch on the pre-auth screens (not just
   the account screen) so the language can be chosen before sign-in.
4. Backfill `lang/de.json` for the API `message` strings that should be localized.
5. Document the header for Scramble so the OpenAPI spec reflects it.
6. Locale negotiation reads `config('app.supported_locales')`. Any environment
   running a cached config (`php artisan config:cache`) must rebuild it after this
   change ships, otherwise the new key resolves to `null`, the negotiated set
   collapses to `['en']`, and every response renders in English regardless of the
   header. Add `config:clear`/`config:cache` to the deploy step.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related PRs:
- Related issues: #108
- Related docs: `lang/de.json`, `config/app.php`, ADR-0003, ADR-0011, ADR-0018
