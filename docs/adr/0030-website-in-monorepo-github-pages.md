# ADR-0030: Move website into monorepo and host on GitHub Pages

## Status

Accepted

## Date

2026-07-17

## Context

The project landing page (open-locker.org) lived in a standalone GitLab
repository (`merona-merlin/open-locker-website`) under a personal namespace,
outside the organization. It was deployed as a Docker image (static Astro
build served by nginx), built by GitLab CI, pushed to the GitLab Container
Registry, and redeployed on a self-hosted Coolify instance via webhook.

Problems with that setup:

- The website source was invisible to the team (personal GitLab namespace,
  separate host from all other project code).
- Deployment depended on privately operated infrastructure (Coolify server,
  GitLab registry, webhook/token wiring) with a single maintainer.
- The monorepo already hosts every other component (backend, mobile app,
  locker client, hardware, docs); the website was the only outlier.

Issue #53 asked to move the website into the monorepo at `website/` and
migrate hosting to GitHub Pages.

## Decision

- Import `open-locker-website` into the monorepo at `website/` via
  `git subtree add`, preserving the full commit history (grafted as a merge
  with unrelated history). The initial import PR must use a merge commit
  rather than squash merging; later website PRs may follow the repository's
  normal merge strategy.
- Remove the GitLab/Coolify deployment artifacts (`.gitlab-ci.yml`,
  `Dockerfile`, `nginx/`); the site is a pure static Astro build.
- Deploy via GitHub Actions (`.github/workflows/deploy-website.yml`): pushes
  to `main` touching `website/**` build the site (pnpm 11 / Node 22) and
  publish `dist/` to GitHub Pages.
- Serve the site at `open-locker.org` as the Pages custom domain; DNS A
  records for the apex point at GitHub Pages' anycast IPs, `www` is a CNAME.
  The legacy `open-locker.github.io/Open-Locker/` URL 301-redirects to the
  domain automatically.
- The `SITE_URL` build-time environment variable selects the canonical origin
  for sitemap, robots, and canonical URLs.

## Rationale

- One repository for all project components; website changes go through the
  same PR/review flow as everything else.
- GitHub Pages removes all self-managed runtime infrastructure (no container,
  no nginx, no registry, no webhook tokens) at zero cost, which fits a static
  site with no server-side logic.
- Subtree import keeps the site's development history without any permanent
  coupling to the old repository.

## Alternatives Considered

### Alternative A: Keep the separate repository (status quo)

- Pros: no migration effort; independent deploy cadence.
- Cons: invisible to the team, personal-namespace bus factor, second host and
  CI system to maintain.
- Why not chosen: contradicts the monorepo strategy; issue #53 explicitly
  asks for consolidation.

### Alternative B: Git submodule in the monorepo

- Pros: website repo could continue to exist unchanged.
- Cons: submodules keep the code outside the monorepo (pointer only), keep
  the old repo alive forever, and complicate cloning/CI.
- Why not chosen: does not actually consolidate anything.

### Alternative C: Keep Coolify/nginx hosting, only move the code

- Pros: no DNS/hosting change; proven setup.
- Cons: retains privately operated deploy infrastructure and secrets; deploy
  pipeline would span GitHub → GitLab registry → Coolify.
- Why not chosen: the operational dependency was the main pain point.

### Alternative D: Org-root Pages repo (`open-locker.github.io`)

- Pros: site would live at the bare github.io URL without a sub-path.
- Cons: requires a second, specially named repository that hosts (or mirrors)
  the site content — recreating the split #53 removes.
- Why not chosen: the custom domain makes the github.io URL irrelevant; a
  one-file redirect stub repo remains an optional cosmetic follow-up.

## Consequences

### Positive

- Website is team-visible, reviewed, and versioned alongside all other
  components; full history preserved.
- Zero-maintenance, zero-cost hosting with automatic TLS (Let's Encrypt via
  GitHub); the Coolify/nginx/GitLab-registry stack can be decommissioned.
- Deploys are reproducible and auditable in GitHub Actions.

### Negative

- Deploys are coupled to `main` pushes; site-only hotfixes require the normal
  branch → `dev` → `main` promotion flow.
- GitHub Pages limits apply (static only, soft 100 GB/month bandwidth) —
  ample for a landing page.

### Risks

- The `github-pages` environment restricts deployable branches; misconfigured
  rules block deploys (mitigation: `main` is allowed by default).
- During migration testing, temporary feature-branch Pages configuration can
  replace production content (mitigation: remove the temporary branch source
  and environment exceptions before the final `main` deployment).
- If the Pages custom domain were ever unclaimed, the domain could be
  squatted on GitHub's shared IPs (mitigation: verify the domain at the
  organization level).

## Rollout / Migration

1. `git subtree add --prefix=website` from the GitLab repo (done).
2. Remove GitLab/Coolify deploy artifacts; add Pages deploy workflow (done).
3. Enable Pages on the repository (done). Feature branches are temporarily
   used for migration testing; switch the authoritative source to the
   `main`-only GitHub Actions workflow before completing the rollout.
4. Point `open-locker.org` DNS at GitHub Pages, set the custom domain,
   and enforce HTTPS (done; cert issued 2026-07-17, redirect verified
   2026-07-20).
5. Follow-ups: archive the GitLab repository with a pointer to the monorepo;
   decommission the Coolify deployment; optionally create a one-file
   `open-locker.github.io` redirect stub; switch Pages to GitHub Actions and
   remove temporary feature-branch deployment access after migration testing.

Fallback: DNS A records can be pointed back at the previous nginx host at any
time; the old deployment remains intact until decommissioned.

## Supersedes / Superseded By

- Supersedes: —
- Superseded by: —

## References

- Related issues: #53
- Related docs: `website/README.md`, `.github/workflows/deploy-website.yml`
