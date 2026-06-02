# ADR-0018: Generate the mobile API client from the live OpenAPI URL

## Status

Proposed

## Date

2026-05-31

## Context

The mobile app's API client (`mobile-app/src/store/generatedApi.ts`) is generated
from the backend's OpenAPI spec via RTK Query codegen (`pnpm generate:api`).

Until now, the spec was produced by `composer export:api` into a committed file
(`locker-backend/api.json`), and the mobile codegen read that file. This created a
drift hazard: the committed `api.json` could be stale relative to the actual backend
controllers, so the mobile client could be generated from an out-of-date contract
without anyone noticing.

Scramble already serves the spec live at `/docs/api.json`, generated from the
controllers on each request — i.e. an always-current source of truth already exists.

## Decision

Generate the mobile API client from the **live** Scramble URL instead of a committed
file:

- The codegen config (`mobile-app/openapi-codegen.config.js`) builds the schema URL
  from `EXPO_PUBLIC_API_BASE_URL` (the same env var the app uses at runtime) and points
  at `/docs/api.json`.
- `locker-backend/api.json` is no longer committed.
- `composer export:api` remains available for an ad-hoc local dump, but is no longer
  part of the codegen pipeline.

## Rationale

The live URL is the canonical, always-current spec. Reusing `EXPO_PUBLIC_API_BASE_URL`
means there is a single, already-configured source for "where the backend is" — no new
config and no hardcoded host. This removes the stale-file class of bug entirely.

## Alternatives Considered

### Alternative A: Keep the committed `api.json`, add a freshness check

- Pros: codegen works offline; spec changes are visible in PR diffs.
- Cons: still possible to forget to regenerate; the check is extra machinery guarding a
  problem the live URL avoids outright.
- Why not chosen: treats the symptom, not the cause.

### Alternative B: Generate `api.json` automatically in CI on every backend change

- Pros: keeps a committed artifact in sync.
- Cons: added CI complexity and commit noise; still a derived file in git.
- Why not chosen: more moving parts than fetching the live spec on demand.

## Consequences

### Positive

- The mobile client can never be generated from a stale spec.
- One source of truth (the running backend); no derived artifact in git.
- No hardcoded backend host — codegen follows `EXPO_PUBLIC_API_BASE_URL`.

### Negative

- The backend must be running to regenerate the client.
- API contract changes no longer appear as an `api.json` diff in PRs.

### Risks

- CI that regenerates/validates the client now needs a reachable backend. Mitigation:
  run codegen against a backend started in the CI job, or skip it where not needed.

## Rollout / Migration

- Switch `openapi-codegen.config.js` to the live URL (done).
- Remove `locker-backend/api.json` from the repo (done).
- Update contributor docs (`CLAUDE.md`, monorepo architecture rule) to describe the
  live-URL flow (done).

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: #84
- Related docs: `CLAUDE.md` (codegen pipeline), `.cursor/rules/general/monorepo-architecture.mdc`
