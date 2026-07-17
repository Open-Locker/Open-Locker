# AGENTS.md

This directory contains the **marketing / informational website** for the open source project **Open-Locker**, served at [open-locker.org](https://open-locker.org/).

- **Location**: `website/` inside the [Open-Locker monorepo](https://github.com/Open-Locker/Open-Locker) (imported from a standalone repo, see issue #53)
- **Scope**: a **static website** (built with **Astro**) that presents the Open-Locker project
- **Deployment**: **GitHub Pages** via `.github/workflows/deploy-website.yml` (builds on pushes to `main` touching `website/**`)

## Goals

- Provide a clear, fast, and accessible landing page for Open-Locker
- Communicate what the Open-Locker project is and how people can participate
- Keep the site build simple and reproducible (static output, no server runtime)

## Non-goals

- This directory is **not** the Open-Locker backend/mobile/hardware code; that lives in the sibling monorepo components
- Do not add runtime server logic (keep it static unless explicitly required)
- Do not add Open-Locker business logic here; link to the main docs/issues instead

## Tech stack

- **Framework**: Astro (MDX, sitemap, Tailwind CSS 4)
- **Output**: static build (`dist/`)
- **Hosting**: GitHub Pages (custom domain `open-locker.org`)

## Project structure (high level)

- `src/pages/`: site routes (Astro pages)
- `src/layouts/`: shared layouts
- `src/components/`: UI components
- `src/content/`: blog content collections
- `public/`: static assets copied as-is

## Local development

- Install dependencies: `pnpm install`
- Run dev server: `pnpm dev`
- Build production output: `pnpm build`
- Preview the production build locally: `pnpm preview`

## CI/CD notes (GitHub Actions)

`.github/workflows/deploy-website.yml` (monorepo root) builds the site with pnpm and deploys `dist/` to GitHub Pages on pushes to `main` that touch `website/**`. Build-time env: `SITE_URL` (canonical origin) and `BASE_PATH` (sub-path, `/` in production). See `README.md` for details.

## Content guidelines

- Prefer **clear, project-focused copy**: what Open-Locker does, why it exists, how to join/contribute
- Keep pages **lightweight**: avoid heavy client-side JS unless it clearly improves UX
- Keep the site **accessible**: semantic HTML, good contrast, meaningful headings/alt text
- Link to upstream resources instead of duplicating deep technical docs:
  - repository, issues, contribution guide, Discord/community links (where available)

## When making changes (for automated agents)

- Make changes in small, reviewable increments
- Avoid introducing new dependencies unless necessary
- Keep deployment assumptions intact (static build, GitHub Pages)
- If updating project facts (stack, components, community links), verify against the monorepo:
  - [Open-Locker/Open-Locker](https://github.com/Open-Locker/Open-Locker)
