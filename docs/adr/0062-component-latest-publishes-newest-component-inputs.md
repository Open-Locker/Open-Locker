# ADR-0062: Component `latest` publishes the newest component-relevant main state

## Status

Accepted

## Date

2026-09-19

## Context

The path-filtered backend and locker-client Docker workflows publish `latest`
and immutable `sha-*` tags when their component inputs change on `main`.
Immediately before publishing, each workflow required its `GITHUB_SHA` to equal
the current repository tip.

That exact-SHA guard conflicts with monorepo path filters. An unrelated commit
can advance `main` while an image is building, reject an otherwise current
component image, and start no replacement workflow. After PR 237 merged as
`a4c17be5`, both backend and client publishes encountered this race. A later
backend-relevant commit happened to recover the backend image, while the client
image remained stale until its workflow was corrected.

[ADR-0043](0043-monorepo-release-strategy.md) decision 7 and
[ADR-0058](0058-trunk-based-development-on-main.md) decision 3 describe Docker
`latest` as the current `main` tip. In a path-filtered monorepo, the useful
meaning is the newest `main` state of that image's inputs, not identity with the
complete repository SHA.

## Decision

1. Each `main` push that changes backend- or client-relevant image inputs must
   eventually publish that newest component state as `latest` and
   `sha-<commit>`.
2. Component-relevant inputs are the corresponding workflow trigger paths:
   - backend: `locker-backend/**` except Markdown and `.cursor/**`, the backend
     git-cliff config, backend Docker workflow, and shared component-release
     workflow;
   - client: `locker-client/**` except Markdown and `.cursor/**`, the client
     git-cliff config, client Docker workflow, and shared component-release
     workflow.
3. An unrelated later `main` commit does not invalidate a publish whose
   component inputs still match `origin/main`.
4. A newer component-relevant `main` push cancels the older run. A last-moment
   path-scoped `git diff` prevents an older component tree from publishing if
   cancellation loses the race.
5. `backend-v*` and `client-v*` tag releases retain the exact-main-tip gate,
   do not cancel one another, and do not write `latest`.
6. `workflow_dispatch` remains verify-only and does not cancel an active
   `main` publish.

## Rationale

Freshness must use the same component inputs that decide whether a replacement
workflow exists. Comparing the complete repository SHA is stricter than the
trigger policy and can strand `latest`. Main-ref cancellation handles normal
supersession, while the path-scoped check immediately before push guards
against cancellation races.

## Alternatives Considered

### Keep the exact repository-tip check

- Benefit: `latest` always names the exact repository tip.
- Drawback: unrelated monorepo commits can abort publication without starting
  a replacement run.
- Rejected because repository-tip identity is not component-content freshness.

### Build every image on every main push

- Benefit: every newer tip creates replacement image runs.
- Drawback: expensive backend and multi-architecture client rebuilds for
  unrelated components and documentation.
- Rejected because it defeats component path filtering.

### Rely only on concurrency cancellation

- Benefit: smaller workflow changes.
- Drawback: late cancellation can still allow an older run to reach publish.
- Rejected because the input-tree comparison is a low-cost additional guard.

### Maintain separate backend and client decisions

- Benefit: each component can evolve independently.
- Drawback: duplicates the same policy, rationale, risks, and lifecycle links.
- Rejected because both workflows intentionally implement one shared policy;
  component-specific paths remain explicit in implementation and validation.

## Consequences

### Positive

- Unrelated `main` movement no longer strands component `latest` tags.
- Newer relevant inputs supersede older image runs.
- Backend and client publication share one documented and validated policy.
- Tag releases and manual dispatch retain their existing behavior.

### Negative

- A component's `latest` may identify a commit behind the repository tip when
  all later commits are irrelevant to that component.
- Trigger paths, freshness pathspecs, and validator expectations must remain
  aligned.

### Risks

- A cancellation arriving during the registry push can still be late. The
  freshness check immediately before push reduces this window, and the newer
  relevant run subsequently overwrites `latest`.
- Fielded deployments must continue pinning `backend-v*`, `client-v*`, or
  immutable `sha-*` tags rather than `latest`.

## Rollout / Migration

1. Apply main-ref cancellation and component-input freshness checks to both
   Docker workflows.
2. Validate their path lists, event behavior, and concurrency policy in
   `.github/scripts/validate_release_workflows.rb`.
3. Merging each workflow change triggers its component build and publication.
4. Confirm `latest` and `sha-<merge-commit>` in GHCR; confirm the client
   manifest contains both `linux/amd64` and `linux/arm64`.

Fallback: restore the exact-SHA guard for the affected component. Publishing
would then require a later component-relevant main push or a component release
tag.

## Supersedes / Superseded By

- Narrows: [ADR-0043](0043-monorepo-release-strategy.md) decision 7 and
  [ADR-0058](0058-trunk-based-development-on-main.md) decision 3 for backend
  and locker-client `latest`. `latest` means the newest component-relevant
  `main` state, not the exact repository tip.
- Superseded by: none.

## References

- Related incident: PR 237 merge `a4c17be5` image publishes were rejected after
  unrelated `main` movement; the backend recovered through a later relevant
  run, while client `latest` remained stale.
- Related ADRs: [ADR-0043](0043-monorepo-release-strategy.md),
  [ADR-0057](0057-skip-component-ci-for-documentation-only-changes.md),
  [ADR-0058](0058-trunk-based-development-on-main.md)
- Related workflows: `.github/workflows/backend-docker.yml`,
  `.github/workflows/client-docker.yml`,
  `.github/workflows/release-workflow-ci.yml`
- Related validation: `.github/scripts/validate_release_workflows.rb`
