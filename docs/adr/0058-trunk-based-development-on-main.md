# ADR-0058: Trunk-based development on `main`

## Status

Accepted

## Date

2026-09-14

## Context

[ADR-0043](0043-monorepo-release-strategy.md) decision 3 established two
long-lived branches: feature work lands on `dev`, `dev` is merged into `main`
when a set of changes is ready, and component tags are cut on `main`. Decision 7
extended that split to distribution channels, giving the integration branch its
own image channel and mobile track.

In practice the split cost more than it protected:

- `main` trailed `dev` by the entire beta stack. ADR-0043's own rollout had to
  open with "bring `main` up to date with `dev` first", and the gap reappeared
  afterwards — at the time of this decision `dev` was 18 commits and 76 files
  ahead of `main`, with the branches diverged.
- The default branch is `main`, so contributors cloning the repository, and the
  public website deployed from `main`, saw stale code.
- Open pull requests targeted mixed bases, which is a recurring source of
  mistakes.
- Hotfixes on `main` require a back-merge to `dev` that is easy to forget.
- The protections `dev` was supposed to provide — isolating unfinished work,
  integrating before release — already happen inside pull requests and their
  required CI.

Two constraints shaped the alternative. First, `main` already triggers website
continuous deployment and publishes the `latest` Docker tag for both the backend
and the locker-client, so removing the promotion step makes every merge more
production-adjacent. Second, the mobile channel question is already settled:
[ADR-0055](0055-tag-only-mobile-store-distribution.md) moved store distribution
to tags only, so mobile no longer depends on a branch mapping at all.

This ADR decides the branch model. It does not change how releases are cut.

## Decision

**1. `main` is the only long-lived branch.**

Feature work uses short-lived branches and pull requests into `main`. The `dev`
branch is retired: its outstanding commits are merged into `main` once, open
pull requests are retargeted, and the remote branch is deleted.

**2. Releases stay tag-driven.**

ADR-0043 decision 4 is unchanged. Merging to `main` is not a release; pushing
`backend-v*`, `client-v*`, or `mobile-v*` is. Component tags are cut on `main`
commits exactly as before. Decisions 1, 2, and 5 of ADR-0043 are likewise
unaffected — this ADR replaces only the branch flow beneath them.

**3. Channels bind to `main` tip or to tags, never to an integration branch.**

| Channel | Source after this ADR |
| --- | --- |
| Website continuous deployment | `main` tip (unchanged) |
| Backend image `latest` | `main` tip (unchanged) |
| Locker-client image `latest` | `main` tip (unchanged) |
| Locker-client image `dev` | retired; no replacement branch channel |
| Mobile store tracks | tags only, per ADR-0055 (unchanged) |

Preview artifacts for a change under review come from that change's pull
request build, not from a shared branch channel.

**4. Fielded deployments pin an immutable version tag.**

Raspberry Pis and any production host must track a `client-v*` or `backend-v*`
tag, never `latest`. This was already the beta guidance; under trunk-based
development it becomes a requirement, because `latest` now moves on every merge
rather than on a deliberate promotion. `latest` means "current `main` tip" and
is for internal and development use.

**5. Trunk stays releasable through author responsibility and automated checks.**

- Branches are short-lived and merge back quickly.
- Changes reach `main` through a pull request. The `main` ruleset, which today
  only blocks deletion and force-pushes, is extended with required status
  checks so a pull request with failing component CI cannot merge.
- **Approving reviews are not required to merge.** The team has no separate QA
  function; the author of a change is responsible for verifying it, including
  running it locally before opening the pull request. A mandatory approval
  would block a correct change on reviewer availability without adding a
  verification step the author has not already performed. Review remains
  welcome and is requested when a change warrants a second opinion.
- Work that is not ready to be visible does not merge, or merges behind a flag.
  Long-running product work uses a draft pull request, not a shared branch.

## Rationale

The team already integrates in pull requests and already releases by tag. The
`dev` branch sat between those two facts without adding a decision point:
promotion merges were mechanical, and the thing they were meant to gate — a
release — was gated by the tag anyway. Removing the branch removes the failure
modes (stale `main`, wrong PR base, forgotten back-merge) without removing a
control.

The cost of the change is concentrated in one place: `main` tip now feeds
`latest` and the website continuously. Decision 4 answers that directly. Once
fielded devices pin tags, a merge to `main` cannot reach hardware, and `latest`
becomes an internal convenience rather than a production channel. The mobile
side needs no equivalent rule because ADR-0055 already removed branch-triggered
store builds.

Release branches remain available if a future release ever needs to be
stabilized independently of trunk. Nothing in this decision prevents cutting
one; the point is that no long-lived branch exists by default.

## Alternatives Considered

### Alternative A: Keep `dev` and `main` (status quo)

- Pros: unfinished integration stays off the production-adjacent branch; the
  promotion merge is an explicit "this is ready" checkpoint; `latest` and the
  website only move when someone promotes.
- Cons: two branches to keep in sync; a promotion pull request per release
  train; `main` goes stale for contributors and the public site; mixed pull
  request bases; hotfix back-merges are easy to forget.
- Why not chosen: the checkpoint it provides is already provided by the release
  tag, and the drift it causes is a demonstrated problem rather than a
  hypothetical one.

### Alternative B: Keep `dev` as an optional preview channel only

- Pros: preserves a rolling internal image without a second integration branch.
- Cons: a branch that is not the integration branch still has to be kept current
  with `main` or it publishes stale previews; contributors still see two
  long-lived branches and can still target the wrong base.
- Why not chosen: pull request builds give per-change previews without a shared
  branch to maintain.

### Alternative C: Trunk-based, but move `latest` and website deployment to tags only

- Pros: no channel moves without a deliberate release; the strongest guarantee
  that a merge cannot reach anything.
- Cons: removes the rolling internal image the team uses for integration
  testing; the public website would then lag behind merged documentation until
  someone tags.
- Why not chosen: decision 4 gets the safety that matters — hardware pins tags —
  without giving up rolling internal artifacts. Gating specific deployments
  behind a manual approval is a better fit than retargeting the channels, and is
  left as follow-up work.

### Alternative D: Trunk-based with a mandatory approving review on `main`

- Pros: a second pair of eyes on everything that reaches the branch feeding
  `latest` and the website; the conventional trunk-based safeguard.
- Cons: with a three-person team and no QA function, an approval requirement
  blocks correct changes on reviewer availability rather than on correctness.
  The author is already the person verifying the change.
- Why not chosen: required status checks catch what automation can catch, and
  author verification covers the rest. Approvals stay available on request
  without being a merge gate; if trunk quality degrades, this is the first
  control to revisit.

## Consequences

### Positive

- One source of truth. No promotion merge and no hotfix back-merge.
- The default branch, the public website, and fresh clones are always current.
- Contributors have one base to target.
- CI configuration and process documentation describe one long-lived branch.
- Conflicts surface on the pull request, where the author is still in context.

### Negative

- Every merge to `main` updates `latest` and redeploys the website, so CI and
  the author's own verification carry weight that the promotion step used to
  share.
- The one-time migration touches workflows, documentation, branch protection,
  open pull requests, and any external system still tracking `dev`.
- Incomplete work needs a flag or a draft pull request rather than a shared
  branch to live on.

### Risks

- **A merge reaches fielded hardware.** Mitigation: decision 4 — Pis pin
  `client-v*` tags; `latest` is not a deployment channel. This must be verified
  on the pilot devices before `dev` is deleted.
- **Trunk breaks and blocks everyone.** Mitigation: decision 5 — required
  status checks on `main`, which today's ruleset lacks entirely, plus the
  author's own verification before opening the pull request. Because no
  approval is required, that author verification is the only human gate; a
  change that cannot be exercised locally should say so on the pull request
  rather than rely on a reviewer to catch it.
- **Work stranded on `dev`.** Mitigation: the migration merges `dev` into `main`
  and retargets open pull requests before the branch is deleted.
- **`workflow_dispatch` overwriting `latest` from a non-`main` ref** remains the
  open risk ADR-0043 flagged; it is tightened as part of this rollout.

## Rollout / Migration

Ordering matters: `dev` is deleted last.

1. Record this decision and supersede the branch-flow parts of ADR-0043.
2. Retarget open pull requests from `dev` to `main`.
3. Merge `dev` into `main` once so the outstanding stack lands.
4. Remove `dev` from workflow push triggers, drop the locker-client `dev` image
   tag, and restrict `workflow_dispatch` so it cannot publish `latest` from a
   non-`main` ref.
5. Update the living process documents that describe promotion —
   `docs/release-checklist.md`, `docs/releases/beta.md`, and
   `locker-client/CUTOVER.md`.
6. Confirm no external system — Coolify, the pilot Pis, Watchtower — still
   tracks branch `dev` or image tag `dev`, and that fielded devices pin a
   version tag.
7. Add required status checks to the `main` ruleset.
8. Delete the remote `dev` branch; contributors delete their local copies.

Historical ADRs are not rewritten. Published `dev` image tags are left in place;
they simply stop being updated.

Fallback: if trunk-based development proves unworkable, a new integration branch
can be created from `main` at any time, and this ADR superseded in turn. Nothing
in the migration is destructive beyond the branch deletion in step 8, which is
performed only after step 3 has preserved its commits.

Deliberate deployment gating — manual approval for production deploys via
`workflow_dispatch` plus protected GitHub Environments — is adjacent to this
decision and deferred to its own issue and ADR. It becomes more attractive under
trunk-based development, but it is not required by it.

## Supersedes / Superseded By

- Supersedes in part: [ADR-0043](0043-monorepo-release-strategy.md) — decision 3
  (branch flow) is replaced by decision 1 here, and the `dev` channel mappings
  in decision 7 are replaced by decision 3 here. The rest of ADR-0043,
  including tag namespaces, SemVer policy, and tag-driven releases, remains in
  force.
- Related: [ADR-0055](0055-tag-only-mobile-store-distribution.md) already
  removed the mobile branch mapping from ADR-0043 decision 7.
- Narrowed by:
  [ADR-0062](0062-component-latest-publishes-newest-component-inputs.md) for
  backend and locker-client `latest`. Each image tracks the newest
  component-relevant `main` state, not the exact repository tip.
- Superseded by: none.

## References

- Related issues: #266 (decision), #50 (release strategy), #102 (`dev` branch CI)
- Related ADRs: [ADR-0043](0043-monorepo-release-strategy.md),
  [ADR-0055](0055-tag-only-mobile-store-distribution.md)
- Related workflows: `.github/workflows/backend-docker.yml`,
  `.github/workflows/client-docker.yml`,
  `.github/workflows/mobile-app-ci.yml`,
  `.github/workflows/locker-client-ci.yml`,
  `.github/workflows/mqtt-contract-ci.yml`,
  `.github/workflows/deploy-website.yml`
- Related docs: `docs/release-checklist.md`, `docs/releases/beta.md`,
  `locker-client/CUTOVER.md`
