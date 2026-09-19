# ADR-0063: Backend `latest` publishes the newest backend-relevant main state

## Status

Accepted

## Date

2026-09-19

## Context

The path-filtered backend Docker workflow publishes `latest` and an immutable
`sha-*` tag when backend-relevant inputs change on `main`. Immediately before
publishing, it required its `GITHUB_SHA` to equal the current `main` tip.

That exact-SHA guard conflicts with monorepo path filters. An unrelated commit
can advance `main` while the backend image is building, reject the otherwise
current backend image, and start no replacement backend workflow. The same race
blocked both backend and client publication after PR 237; the backend happened
to recover only because a later backend-relevant commit triggered another run.

[ADR-0062](0062-client-latest-publishes-newest-client-inputs.md) resolved this
for the locker client. The backend has the same trigger and publication model
and needs the same component-scoped freshness rule.

## Decision

1. Each `main` push that changes backend-relevant inputs must eventually
   publish that newest backend state as `latest` and `sha-<commit>`.
2. Backend-relevant inputs are the workflow trigger paths:
   `locker-backend/**` except Markdown and `.cursor/**`, plus
   `.github/git-cliff/backend.toml`,
   `.github/workflows/backend-docker.yml`, and
   `.github/workflows/component-release.yml`.
3. An unrelated later `main` commit does not invalidate a publish whose backend
   inputs still match `origin/main`.
4. A newer backend-relevant `main` push cancels the older run. A last-moment
   path-scoped `git diff` prevents an older backend tree from publishing if
   cancellation loses the race.
5. `backend-v*` tag releases retain the exact-main-tip gate and do not cancel
   one another.
6. `workflow_dispatch` remains verify-only and does not cancel an active
   `main` publish.

## Rationale

The workflow should define freshness using the same component inputs that
decide whether a replacement workflow exists. Main-ref cancellation handles
normal supersession, while the path-scoped check immediately before push
provides a second guard against cancellation races.

## Alternatives Considered

### Keep the exact repository-tip check

- Benefit: `latest` always names the exact repository tip.
- Drawback: unrelated monorepo commits can strand backend `latest` without a
  replacement run.
- Rejected because repository-tip identity is stricter than backend-content
  freshness.

### Build the backend on every main push

- Benefit: every newer tip creates a replacement run.
- Drawback: rebuilds the backend for client, mobile, website, and documentation
  changes.
- Rejected because it defeats component path filtering.

### Rely only on concurrency cancellation

- Benefit: smaller workflow change.
- Drawback: a late cancellation can still reach publication.
- Rejected because the input-tree comparison is a low-cost additional guard.

## Consequences

### Positive

- Unrelated `main` movement no longer strands backend `latest`.
- Newer backend inputs supersede older image runs.
- Backend and client main-image publication now use the same policy.
- Tag releases and manual dispatch retain their existing behavior.

### Negative

- Backend `latest` may identify a commit behind the repository tip when all
  later commits are backend-irrelevant.
- Trigger paths, freshness pathspecs, and validator expectations must remain
  aligned.

### Risks

- A cancellation arriving during the registry push can still be late. The
  freshness check immediately before push reduces this window, and the newer
  backend run subsequently overwrites `latest`.
- Production deployments must continue pinning `backend-v*` or immutable
  `sha-*` tags rather than `latest`.

## Rollout / Migration

1. Merge the workflow, validator, and ADR to `main`.
2. The change to `backend-docker.yml` triggers a backend build and publication.
3. Confirm both `latest` and `sha-<merge-commit>` are present in GHCR.
4. If a newer backend-relevant run supersedes this one, confirm that newer run
   performs the publication.

Fallback: restore the exact-SHA guard. Publishing would then require a later
backend-relevant main push or a `backend-v*` tag.

## Supersedes / Superseded By

- Narrows: [ADR-0043](0043-monorepo-release-strategy.md) decision 7 and
  [ADR-0058](0058-trunk-based-development-on-main.md) decision 3 for backend
  `latest` only. It means the newest backend-relevant `main` state, not the
  exact repository tip.
- Complements: [ADR-0062](0062-client-latest-publishes-newest-client-inputs.md)
  applies the same rule to locker-client `latest`.
- Superseded by: none.

## References

- Related incident: the backend publish for PR 237 merge `a4c17be5` was rejected
  after `main` advanced; a later backend-relevant run recovered `latest`.
- Related ADRs: [ADR-0043](0043-monorepo-release-strategy.md),
  [ADR-0058](0058-trunk-based-development-on-main.md),
  [ADR-0062](0062-client-latest-publishes-newest-client-inputs.md)
- Related workflows: `.github/workflows/backend-docker.yml`,
  `.github/workflows/release-workflow-ci.yml`
- Related validation: `.github/scripts/validate_release_workflows.rb`
