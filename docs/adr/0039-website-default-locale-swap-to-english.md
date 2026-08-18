# ADR-0039: Swap website default locale to English, German moves to /de/

> **Renumbered from ADR-0032** — ADR numbers were deduplicated and put in date order from 0018 up; see #214.

## Status

Accepted

## Date

2026-07-21

## Context

ADR-0040 made German the default (unprefixed) locale for open-locker.org,
with English additive under `/en/`, specifically to preserve the site's
existing indexed URLs and SEO standing at the time English was added.

The project has since decided the website's primary audience is
international/English-first, with German as a secondary, regionally-scoped
locale. This ADR reverses the default: **English becomes the unprefixed
default locale; German moves to `/de/…`.**

This directly contradicts ADR-0040's stated rationale (root URLs held
constant to protect existing indexing), so it must be recorded as a
superseding decision rather than an amendment.

At the time of this change, `website/` exists only on a feature branch
(`feat/92-starlight-docs`) that has not been merged to `main`. That branch is
temporarily deployed to open-locker.org for migration testing, so its routes
are publicly reachable even though the change has not completed the normal
`dev` to `main` promotion flow.

## Decision

- **English is the default locale at the root** (`open-locker.org/…`);
  **German lives under `/de/…`** (`prefixDefaultLocale: false`,
  `defaultLocale: 'en'`).
- The general Astro router does not create locale fallbacks. Marketing and
  legal pages are translated explicitly, preventing unintended routes such as
  `/de/privacy-policy/`. Starlight uses English root content as the fallback
  for missing German documentation pages.
- Physical content is swapped to match: `src/pages/*` (previously the
  German root pages) move to `src/pages/de/*`; the former `src/pages/en/*`
  pages move to the root. Content collections follow the same pattern:
  `src/content/blog/en/*` and `src/content/docs/en/dokumentation/*` become
  the unprefixed default; the former root/German content moves under a
  `de/` prefix.
- **Legal pages remain unchanged in substance**: the German Impressum/
  Datenschutz text is still the legally binding original (per ADR-0040);
  only its URL moves to `/de/impressum/` and `/de/datenschutz/`. The English
  pages use English slugs (`/imprint/` and `/privacy-policy/`) and keep their
  "courtesy translation, German is binding" notice, now pointing at the
  `/de/` URLs.
- `hreflang="x-default"` now points at the English URL instead of German.
- Starlight's docs sidebar base label switches from German (`Dokumentation`)
  to English (`Documentation`), with German supplied via `translations`.

## Alternatives Considered

### Alternative A: Keep German at root, translate UI copy to lead with English

- Pros: no URL churn, no redirect concern.
- Cons: doesn't achieve the actual goal — English needs to be the canonical,
  unprefixed identity of the site for an international audience.
- Why not chosen: doesn't satisfy the requirement.

### Alternative B: Prefix both locales (`/en/…` and `/de/…`), nothing at root

- Pros: symmetric, avoids re-litigating "which locale owns root" in the
  future.
- Cons: still breaks every existing German root URL; adds a redirect or
  root-splash requirement for bare `/`.
- Why not chosen: no benefit over making English the root locale given the
  goal is an English-first site, not a symmetric one.

## Consequences

### Positive

- Root URLs now match the primary, English-first audience.
- Existing component-level i18n abstraction (`src/i18n/index.ts`,
  `localizePath`/`getLocaleFromUrl`) needed changes in exactly one place;
  all consuming components picked up the new behavior without further
  edits, confirming ADR-0040's abstraction paid off.

### Negative

- Root URLs shared by both languages (for example `/blog/`) now serve English
  instead of German and cannot redirect without hiding the new default
  content. Language-specific German legal slugs can redirect to `/de/`.
- Content maintenance burden (dual-locale upkeep) is unchanged from
  ADR-0040, just mirrored.

### Risks

- Existing links to shared root paths now resolve to English rather than the
  formerly indexed German content. This is an accepted consequence of making
  English canonical. The old `/datenschutz/` and `/impressum/` routes use
  static HTML redirects to their German `/de/` equivalents.

## Rollout / Migration

1. Flip `astro.config.mjs` (`i18n.defaultLocale`, `sitemap()` i18n block,
   Starlight root/de locales and sidebar label/translations).
2. Swap `src/i18n/index.ts`'s locale-prefix logic (`/de` prefix instead of
   `/en`).
3. Move `src/pages/*` ↔ `src/pages/en/*` and `src/content/docs/dokumentation`
   ↔ `src/content/docs/en/dokumentation` so default-locale content lives
   unprefixed and German content lives under `de/`.
4. Move English legal pages to `/privacy-policy/` and `/imprint/`, keep German
   at `/de/datenschutz/` and `/de/impressum/`, and update language alternates.
5. Add static redirects from the former German legal routes
   (`/datenschutz/`, `/impressum/`) to their `/de/` equivalents.

## Supersedes / Superseded By

- Supersedes: ADR-0040 (website-url-path-localization) — the "German stays
  at root" decision specifically; the URL-path-prefix localization
  mechanism itself, translation storage strategy, and legal-page approach
  from ADR-0040 remain in effect.
- Superseded by: —

## References

- Related ADR: ADR-0040 (website-url-path-localization)
- Related issues: #92
