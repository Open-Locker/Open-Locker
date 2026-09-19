# ADR-0062: Client `latest` publishes the newest client-relevant main state

## Status

Accepted

## Date

2026-09-19

## Context

Path-filtered `client-docker.yml` publishes multi-arch `latest` plus an
immutable `sha-*` tag from `main` when locker-client inputs change. A later
exact-SHA guard compared `GITHUB_SHA` to `origin/main` immediately before
publish so `latest` could not move backwards.

That guard assumed every later `main` commit was client-relevant. In this
monorepo that is false. After PR 237 merged as `a4c17be5`, client verification
passed, then unrelated `main` commits advanced the tip to `3ae1e919`. The SHA
guard failed, so neither `latest` nor `sha-a4c17be5` was published. Later
commits did not touch locker-client inputs, path filters started no replacement
build, and `latest` stayed at `c3c423ea`.

[ADR-0043](0043-monorepo-release-strategy.md) decision 7 and
[ADR-0058](0058-trunk-based-development-on-main.md) decision 3 still describe
Docker `latest` as "current `main` tip". For locker-client that wording is too
literal: the publish trigger is the client input set, not the repository SHA.
Backend image publication is unchanged. Tag-driven `client-v*` releases still
require the current `main` tip. `workflow_dispatch` remains verify-only.

## Decision

1. Each `main` push that changes client-relevant inputs must eventually publish
   that newest client state as multi-arch `latest` and `sha-<commit>`.
2. Client-relevant inputs are the same paths that trigger the workflow:
   `locker-client/**` except Markdown and `.cursor/**`, plus
   `.github/git-cliff/client.toml`, `.github/workflows/client-docker.yml`, and
   `.github/workflows/component-release.yml`.
3. An unrelated later `main` commit must not invalidate a client publish whose
   client inputs still match `origin/main`.
4. A newer client-relevant `main` push must supersede an older one. Main-ref
   concurrency cancels the older run; a last-moment `git diff` of those inputs
   against `origin/main` refuses a stale publish if cancellation loses the race.
5. `client-v*` tag releases keep the exact `main` tip gate, do not cancel one
   another, and still do not write `latest`.
6. `workflow_dispatch` does not publish. A manual run on `main` shares the main
   ref group but must not cancel an in-progress main publish.

## Rationale

Path filters already decide when a replacement client build exists. Comparing
the full `main` SHA fights that rule and can drop `latest` with no successor.
Cancel-on-main plus an input-tree check is the smallest pair that both
recovers from unrelated tip movement and prevents an older client tree from
winning `latest`.

## Alternatives Considered

### Alternative A: Keep the exact `main` SHA guard

- Pros: `latest` can never point at a commit behind the current tip.
- Cons: unrelated monorepo commits abort publish; path filters start no
  replacement; `latest` can stall indefinitely.
- Why not chosen: this is the incident.

### Alternative B: Remove the pre-publish check and rely only on concurrency

- Pros: smallest workflow edit.
- Cons: if two client-relevant runs overlap and cancellation is late, the older
  tree can still push `latest` last.
- Why not chosen: the input-tree check is a cheap last-moment guard.

### Alternative C: Apply the same policy to backend images

- Pros: backend-docker.yml has the same SHA-plus-path-filter shape.
- Cons: no demonstrated backend incident; backend `latest` still follows the
  literal tip wording in ADR-0043 and ADR-0058.
- Why not chosen: do not broaden the change without a backend need.

### Alternative D: Always republish client images from every `main` push

- Pros: `latest` would track exact tip automatically.
- Cons: expensive multi-arch rebuilds for unrelated backend, mobile, and docs
  work; fights ADR-0057 documentation exclusions.
- Why not chosen: client content, not repository SHA, is what `latest` must
  represent.

## Consequences

### Positive

- Unrelated `main` movement no longer strands locker-client `latest`.
- A newer client-relevant push cancels or refuses an older publication, so
  `latest` cannot regress to an older client tree.
- Tag releases and manual dispatch stay on the existing contract.

### Negative

- Client `latest` may be built from a `main` commit that is no longer the
  repository tip when later commits are client-irrelevant.
- The input-tree check and the workflow path filters can drift if only one is
  updated.

### Risks

- A very late cancel during `docker push` can still let an older tree finish
  after a newer one. Mitigation: the input-tree check sits immediately before
  push; the newer run still overwrites `latest` if it reaches publish.
- Backend retains the original race. Mitigation: out of scope; fix only if the
  same incident appears there.
- Fielded Pis must keep pinning `client-v*`, never `latest` (ADR-0058).

## Rollout / Migration

1. Land this workflow, validator, and ADR on `main`. Changing
   `client-docker.yml` is itself a client-relevant input, so the merge publishes
   a replacement multi-arch `latest` and `sha-*` without manual dispatch.
2. Confirm GHCR shows a new `latest` digest for `linux/amd64` and `linux/arm64`.
3. If publication is skipped because a still-newer client-relevant commit won
   concurrency, that newer run is the recovery path.

Fallback: restore the exact SHA guard and dispatch the workflow only as a
verify build; a follow-up client-relevant `main` push, or a `client-v*` tag, is
required to publish.

## Supersedes / Superseded By

- Narrows: [ADR-0043](0043-monorepo-release-strategy.md) decision 7 and
  [ADR-0058](0058-trunk-based-development-on-main.md) decision 3 for
  locker-client `latest` only. `latest` means the newest client-relevant `main`
  state, not the exact repository tip. Tag-driven releases and backend `latest`
  are unchanged.
- Superseded by: none.

## References

- Related incident: PR 237 / `a4c17be5` client publish aborted after `main`
  moved to `3ae1e919`; `latest` remained `c3c423ea`
- Related ADRs: [ADR-0043](0043-monorepo-release-strategy.md),
  [ADR-0057](0057-skip-component-ci-for-documentation-only-changes.md),
  [ADR-0058](0058-trunk-based-development-on-main.md)
- Related workflows: `.github/workflows/client-docker.yml`,
  `.github/workflows/release-workflow-ci.yml`
- Related tests: `.github/scripts/validate_release_workflows.rb`
