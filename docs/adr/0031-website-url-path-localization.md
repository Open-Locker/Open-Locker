# ADR-0031: URL-path-based localization for the website

## Status

Accepted

## Date

2026-07-17

## Context

The landing page at open-locker.org (`website/`, see ADR-0030) is German-only.
Issue #90 asks for an English version. The site is a static Astro build on
GitHub Pages, so there is no server-side logic available for language
negotiation. The language mechanism determines the site's public URL
structure, which is expensive to change once indexed and linked.

The technical documentation planned in #92 (Starlight) is out of scope here
and is expected to be English-only.

## Decision

- Localize via **URL path prefixes** using Astro's built-in i18n routing.
- **German remains the default locale at the root** (`open-locker.org/…`,
  unchanged URLs); **English lives under `/en/…`**
  (`prefixDefaultLocale: false`).
- Every page emits `hreflang` alternate links and locale-aware canonical
  URLs; the sitemap lists both locales. A visible language switcher links the
  two versions of each page.
- No automatic redirect based on browser language (not possible statically);
  visitors land on the URL they follow and can switch.
- **Where translations live:** UI/marketing strings in typed TypeScript
  dictionaries (`src/i18n/de.ts` / `en.ts`; the English dictionary is typed
  against the German shape, so a missing key is a type error). Long-form
  documents (blog posts, legal pages) are one file per locale (content
  collection subfolders `blog/de/`, `blog/en/` with matching filenames so
  URLs pair up).
- **Legal pages** (Impressum, Datenschutz) are translated as courtesy
  translations that state explicitly that the German version is the legally
  binding one.

## Rationale

- Path-prefix URLs are the SEO-standard approach for multilingual sites:
  both languages are crawlable, indexable, and shareable.
- Keeping German at the root preserves every existing URL, canonical tag,
  and search ranking — the English version is purely additive.
- Astro supports exactly this scheme natively; no custom routing code.

## Alternatives Considered

### Alternative A: Language subdomain (`en.open-locker.org`)

- Pros: clean separation; also SEO-indexable.
- Cons: requires extra DNS records and either a second Pages site or custom
  routing; splits the site across origins for no benefit at this scale.
- Why not chosen: operational overhead with no advantage over path prefixes.

### Alternative B: Browser-language auto-negotiation (same URLs)

- Pros: no URL changes; automatic for visitors.
- Cons: impossible on static GitHub Pages (no server); client-side JS
  redirects are fragile and hostile to crawlers and shared links.
- Why not chosen: ruled out by the hosting platform and SEO.

### Alternative C: Prefix both locales (`/de/…` and `/en/…`)

- Pros: symmetric structure.
- Cons: breaks every existing German URL (or requires a permanent redirect
  layer); loses accumulated indexing of the current root URLs.
- Why not chosen: the German site is live and indexed; keeping it at the
  root is strictly safer.

## Consequences

### Positive

- Existing German URLs and SEO are untouched; English is additive under
  `/en/`.
- Both languages indexable with correct `hreflang` signals.
- Foundation laid: future locales or the Starlight docs (#92) can adopt the
  same convention if ever needed.

### Negative

- Content maintenance doubles for localized pages: changes must be applied
  to both languages (mitigated by shared templates with per-locale content).
- No automatic language detection; first-time visitors from abroad land on
  German unless they follow an `/en/` link.

### Risks

- Translations drifting out of date relative to the German source
  (mitigation: keep both locales' content side by side so diffs touching one
  are visible next to the other).

## Rollout / Migration

1. Enable Astro i18n (`defaultLocale: 'de'`, `locales: ['de', 'en']`,
   `fallback: { en: 'de' }`, `prefixDefaultLocale: false`).
2. Extract shared templates; add English page variants under `/en/`.
3. Add language switcher, `hreflang` alternates, locale-aware sitemap.
4. Blog: German posts keep their existing URLs; all posts translated to
   English under `/en/blog/`. Future posts without a translation fall back
   to a redirect to the German version (no empty `/en/` stubs).

No migration needed for existing URLs; nothing changes for German visitors.

## Supersedes / Superseded By

- Supersedes: —
- Superseded by: ADR-0032 (partially — the "German stays at root" decision
  only; the URL-path-prefix mechanism, translation storage, and legal-page
  approach remain in effect)

## References

- Related issues: #90, #92
- Related docs: ADR-0030, `website/README.md`
