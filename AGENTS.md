# AGENTS.md

This repository contains the **marketing / informational website** for the open source project **Open-Locker**.

- **Upstream project (code + hardware + docs)**: [Open-Locker/Open-Locker](https://github.com/Open-Locker/Open-Locker)
- **This repo's scope**: a **static website** (built with **Astro**) that presents the Open-Locker project
- **Deployment**: **Docker image** serving the static build via **NGINX** (and optionally deployed through GitLab CI/CD + Coolify)

## Goals

- Provide a clear, fast, and accessible landing page for Open-Locker
- Communicate what the Open-Locker project is and how people can participate
- Keep the site build simple and reproducible (static output + containerized runtime)

## Non-goals

- This repo is **not** the Open-Locker backend/mobile/hardware monorepo
- Do not add runtime server logic (keep it static unless explicitly required)
- Do not add Open-Locker business logic here; link to upstream docs/issues instead

## Tech stack

- **Framework**: Astro
- **Output**: static build (`dist/`)
- **Container runtime**: NGINX serving the static files

## Project structure (high level)

- `src/pages/`: site routes (Astro pages)
- `src/layouts/`: shared layouts
- `src/components/`: UI components
- `public/`: static assets copied as-is
- `nginx/nginx.conf`: NGINX configuration used by the container image

## Local development

- Install dependencies:

  - `pnpm install`

- Run dev server:

  - `pnpm dev`

- Build production output:

  - `pnpm build`

- Preview the production build locally:

  - `pnpm preview`

## Docker workflow

This repository builds a Docker image that contains the static Astro build and serves it via NGINX.

- Build:
  - `docker build -t open-locker-webpage .`
- Run:
  - `docker run --rm -p 8080:8080 open-locker-webpage`

## CI/CD notes (GitLab)

The pipeline in `.gitlab-ci.yml` builds and pushes Docker images to the GitLab Container Registry:

- `:$CI_COMMIT_SHA` for immutable builds
- `:$CI_COMMIT_REF_SLUG` for branch builds
- `:latest` on `main`

Optionally, a deploy job can trigger a redeploy via a **Coolify** webhook (requires `COOLIFY_WEBHOOK` and `COOLIFY_TOKEN` variables).

## Content guidelines

- Prefer **clear, project-focused copy**: what Open-Locker does, why it exists, how to join/contribute
- Keep pages **lightweight**: avoid heavy client-side JS unless it clearly improves UX
- Keep the site **accessible**: semantic HTML, good contrast, meaningful headings/alt text
- Link to upstream resources instead of duplicating deep technical docs:
  - repository, issues, contribution guide, Discord/community links (where available)

## When making changes (for automated agents)

- Make changes in small, reviewable increments
- Avoid introducing new dependencies unless necessary
- Keep deployment assumptions intact (static build + NGINX in Docker)
- If updating project facts (stack, components, community links), verify against upstream:
  - [Open-Locker/Open-Locker](https://github.com/Open-Locker/Open-Locker)

