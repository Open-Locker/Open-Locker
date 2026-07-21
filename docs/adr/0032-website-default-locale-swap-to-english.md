# ADR-0032: Swap website default locale to English, German moves to /de/

## Status

Accepted

## Date

2026-07-21

## Context

ADR-0031 made German the default (unprefixed) locale for open-locker.org,
with English additive under `/en/`, specifically to preserve the site's
existing indexed URLs and SEO standing at the time English was added.

The project has since decided the website's primary audience is
international/English-first, with German as a secondary, regionally-scoped
locale. This ADR reverses the default: **English becomes the unprefixed
default locale; German moves to `/de/…`.**

This directly contradicts ADR-0031's stated rationale (root URLs held
constant to protect existing indexing), so it must be recorded as a
superseding decision rather than an amendment.

At the time of this change, `website/` exists only on a feature branch
(`feat/92-starlight-docs`) that has not been merged to `main`; the
production deployment (triggered on push to `main`) still serves the
ADR-0031 layout (German at root). No redirect layer for the old German
URLs has been added yet — see Consequences.

## Decision

- **English is the default locale at the root** (`open-locker.org/…`);
  **German lives under `/de/…`** (`prefixDefaultLocale: false`,
  `defaultLocale: 'en'`).
- Fallback direction flips: missing German content falls back to English
  (`fallback: { de: 'en' }`).
- Physical content is swapped to match: `src/pages/*` (previously the
  German root pages) move to `src/pages/de/*`; the former `src/pages/en/*`
  pages move to the root. Content collections follow the same pattern:
  `src/content/blog/en/*` and `src/content/docs/en/dokumentation/*` become
  the unprefixed default; the former root/German content moves under a
  `de/` prefix.
- **Legal pages remain unchanged in substance**: the German Impressum/
  Datenschutz text is still the legally binding original (per ADR-0031);
  only its URL moves to `/de/impressum/` and `/de/datenschutz/`. The English
  pages keep their "courtesy translation, German is binding" notice, now
  pointing at the `/de/` URLs.
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
  edits, confirming ADR-0031's abstraction paid off.

### Negative

- Every German URL that may have been indexed under ADR-0031's root layout
  (e.g. `open-locker.org/dokumentation/`) now 404s unless a redirect is
  added; German search ranking accumulated since ADR-0031 is lost without
  one.
- Content maintenance burden (dual-locale upkeep) is unchanged from
  ADR-0031, just mirrored.

### Risks

- If this lands on `main` before a redirect layer exists for the old German
  root paths, external links and search results pointing at the
  ADR-0031-era German URLs will break. Mitigation: add static redirect
  stubs (or a GitHub Pages `_redirects`-equivalent) from the old German
  paths to their new `/de/` equivalents as part of, or immediately after,
  merging this change to `main` — tracked as follow-up work, not yet done
  as of this ADR.

## Rollout / Migration

1. Flip `astro.config.mjs` (`i18n.defaultLocale`, `i18n.fallback`,
   `sitemap()` i18n block, Starlight sidebar label/translations).
2. Swap `src/i18n/index.ts`'s locale-prefix logic (`/de` prefix instead of
   `/en`).
3. Move `src/pages/*` ↔ `src/pages/en/*` and `src/content/docs/dokumentation`
   ↔ `src/content/docs/en/dokumentation` so default-locale content lives
   unprefixed and German content lives under `de/`.
4. Fix relative import depths in moved page files and the hardcoded
   "German version is binding" link in the legal pages.
5. **Follow-up, not yet done:** add redirect stubs for the old (ADR-0031)
   German root URLs before/at merge to `main`.

## Supersedes / Superseded By

- Supersedes: ADR-0031 (website-url-path-localization) — the "German stays
  at root" decision specifically; the URL-path-prefix localization
  mechanism itself, translation storage strategy, and legal-page approach
  from ADR-0031 remain in effect.
- Superseded by: —

## References

- Related ADR: ADR-0031 (website-url-path-localization)
- Related issues: #92
