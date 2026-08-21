# ADR-0035: Per-component release strategy for the monorepo

## Status

Proposed

## Date

2026-08-14

## Context

The monorepo ships three independently deployable components:

| Component | Artifact | Destination |
| --- | --- | --- |
| `locker-backend` | Docker image | `ghcr.io/open-locker/locker-backend` → production host |
| `locker-client` | Docker image | `ghcr.io/open-locker/locker-client` → Raspberry Pis in the field |
| `mobile-app` | EAS build | TestFlight / app stores |

`website/` deploys continuously from `main` via GitHub Pages and is outside this
release model.

Path-filtered CI already exists for these components, and images/builds already
publish. What is missing is **versioning and a deliberate release policy**: the
repository has had no component tags and no GitHub Releases. Images are published
as `latest` plus a commit SHA, which makes "which version is on that Pi" and
rollback hard to answer.

The three components cannot ship in lockstep. Mobile waits on store review, Pis
update on their own schedule, and the backend deploys quickly. A single
repo-wide version would claim synchronisation that does not exist.

Legacy Flutter and shared generated packages are out of scope; they are no longer
part of the monorepo.

This ADR decides the release **policy** needed for a first beta. Workflow
implementation, changelog automation wiring, and deeper compatibility tooling
are follow-up work after this decision is accepted.

## Decision

**1. Each component is versioned and released independently under its own tag
namespace.**

```
backend-vX.Y.Z
client-vX.Y.Z
mobile-vX.Y.Z
```

Tag names use short English component names (`backend`, `client`, `mobile`).
The `locker-` directory prefix is omitted from tags. Existing workflow patterns
that use `locker-client-v*` must be retargeted to `client-v*` as part of
rollout (no historical tags exist yet).

Pushing a tag is what publishes a release. The image or build is labelled with
that version, alongside `latest` (when applicable) and the commit SHA.

**2. Version numbers follow standard Semantic Versioning per component.**

Each component has its own public surface and therefore its own SemVer line
([semver.org](https://semver.org/spec/v2.0.0.html)):

- **X (major)** — backward-incompatible change for that component's consumers
  (HTTP API, MQTT contract, mobile app behaviour that breaks older clients, and
  similar). Deliberate majors require an ADR.
- **Y (minor)** — backward-compatible new functionality.
- **Z (patch)** — backward-compatible bug fixes.

Components may diverge (for example `backend-v1.4.2` with `mobile-v1.1.0`).
That is expected. They are one product operationally, but they are not one
deployable artifact.

All three start at `1.0.0` for the first production-line release. Pre-releases
use SemVer pre-release labels, for example `backend-v1.0.0-beta.1`, and are
allowed for the first beta cut.

Contract changes (OpenAPI / AsyncAPI) must be called out explicitly in the
generated release notes for that release. The version digit itself follows
standard SemVer for the component being released, not a special
"contract-only" numbering scheme.

**3. Day-to-day development happens on `dev`; releases are cut from `main`.**

The branch flow is:

1. Feature work lands on `dev` (via PRs into `dev`).
2. When a set of changes is ready to ship, `dev` is merged into `main`.
3. A maintainer creates the component tag on the intended `main` commit.
4. That tag publishes the versioned artifact and GitHub Release.

`dev` is the integration branch. `main` is the release branch. Merging to
`main` still does **not** by itself create a SemVer release — the tag does.
Hotfixes may land directly on `main` when needed, then be merged back to `dev`
so the branches do not diverge permanently.

**4. Releases are cut deliberately by tagging; merging to `main` is not a
release.**

A maintainer pushes the component tag when a release is intended. Several merges
may be batched into one version. There is no fixed calendar; urgent fixes may
ship immediately. A component with nothing new is simply not tagged.

**5. Tags create versioned releases; `workflow_dispatch` does not invent versions.**

- Tag push (`backend-v*`, `client-v*`, `mobile-v*`) creates the immutable
  versioned artifact and the GitHub Release.
- `workflow_dispatch` is for rebuild, verification, or retry from an allowed
  ref. It must not publish `latest` from non-`main` branches and must not mint a
  new SemVer without an explicit tag.

**6. The backend is the compatibility authority and stays backward compatible
within the support window.**

Newer backends must keep working with older supported `locker-client` and
mobile versions. Each client release may document a minimum backend version.
We do **not** maintain a hand-written compatibility matrix in docs; it would go
stale.

**7. Distribution channels: rolling images plus staged mobile tracks.**

For Docker artifacts (`backend`, `client`):

- `latest` means current production `main` tip, not necessarily the newest
  SemVer tag.
- The `dev` branch never publishes `latest`.
- If rolling artifacts from the integration branch are wanted, they use a
  separate `dev` image/channel tag.

For the mobile app the store tracks map to branches and tags as follows:

| Source | Mobile distribution |
| --- | --- |
| `dev` | Internal tester builds only (sideload / Expo internal / equivalent). **Not** submitted to App Store or Play Store tracks. |
| `main` | Store beta tracks: TestFlight (iOS) and Android beta / internal testing as configured. |
| Tag `mobile-vX.Y.Z` (including `-beta.N` when used) | Versioned release candidate or production release submitted as the release for that version. |

So: develop and smoke-test off-store on `dev`, put a broader beta on store tracks from `main`, and cut the immutable release from the SemVer tag. Builds from `dev` should still be produced when useful for QA; they must not enter App Store / Play production or public beta submission paths reserved for `main` and tags.

Immutable SemVer tags remain the source of truth for "which release is
deployed" and for rollback.

**8. Changelogs are generated per component with `git-cliff`.**

Conventional Commits are the input. `git-cliff` filters by path and tag
pattern so each GitHub Release lists only that component's commits, for
example:

- `locker-backend/**` with tags matching `backend-v*`
- `locker-client/**` with tags matching `client-v*`
- `mobile-app/**` with tags matching `mobile-v*`

GitHub's built-in "generate release notes" is rejected because it cannot split
a monorepo by component. No committed `CHANGELOG.md` is required initially;
the GitHub Release body is the source of truth. Squash-merge PR titles must
remain Conventional Commits so generation stays useful.

**9. Support window for the beta era.**

Supported means "we will fix bugs against it": the latest patch of the current
minor line and the latest patch of the previous minor line for each component.
Older images remain pullable for rollback but are unsupported.

**10. Compatibility tooling is follow-up work, not a beta blocker.**

For the first beta, operators rely on tagged versions, release notes, and
(where already present) version visibility. The following are **explicitly
deferred** to later issues after versioned releases exist:

- CI contract diffs (for example `oasdiff` for OpenAPI, `asyncapi diff` for
  MQTT) against the previous supported release
- Cross-version integration matrices (new backend × previous client / mobile)
- Runtime user-facing incompatibility warnings when a client talks to an
  unsupported backend

Those tools matter, especially runtime signalling, but they need immutable
version tags first and are not required to cut a beta under this strategy.

## Rationale

Independent tags match how the system actually deploys. Standard SemVer matches
how humans and automation already interpret `feat` / `fix` / breaking changes,
including `git-cliff` bump hints. Keeping contract impact in release notes
preserves cross-component signal without overloading the version number.

Using `dev` for integration and `main` for release keeps unfinished work off the
production channel while still making releases a deliberate tag on `main`.
Mobile follows the same idea with store tracks: off-store internals from `dev`,
TestFlight / Android beta from `main`, versioned release from `mobile-v*` tags.

Deferring compatibility CI and runtime checks keeps the beta path short: decide
naming and process now, implement workflows next, add deeper safety nets once
real versioned artifacts exist.

## Alternatives Considered

### Alternative A: A single repo-wide version (`vX.Y.Z`)

- Pros: one number; matches the unused `v*` trigger in `docker-ghcr.yml`.
- Cons: implies lockstep deployment; forces store submissions for unrelated
  backend fixes.
- Why not chosen: the synchronisation would be false.

### Alternative B: Release automatically on every merge to `main`

- Pros: no forgotten tag step.
- Cons: no batching; burns versions; costly for mobile.
- Why not chosen: releases to hardware and stores must stay deliberate.

### Alternative C: Contract-tied SemVer (X/Y only move on HTTP/MQTT contract
change; features are always patch)

- Pros: version digit signals cross-component risk directly.
- Cons: large user-facing features look like patches; fights Conventional
  Commit tooling; confusing for mobile/store audiences.
- Why not chosen: standard SemVer plus explicit contract notes in release
  notes is clearer and automates better.

### Alternative D: Keep `locker-client-v*` tag prefix

- Pros: matches today's client workflow pattern string.
- Cons: inconsistent with short `backend-` / `mobile-` names; no tags exist yet.
- Why not chosen: rename once to `client-v*` while the cost is zero.

### Alternative E: Build runtime compatibility gates before the first beta

- Pros: users get early warnings for mismatched versions.
- Cons: blocks beta on tooling that needs versioned releases to be meaningful.
- Why not chosen: policy and tags first; runtime/CI compatibility as follow-up.

## Consequences

### Positive

- Deployed versions become answerable and rollback becomes possible.
- Components release on independent cadences.
- Release notes stay maintainable via Conventional Commits and `git-cliff`.
- Beta can ship with pre-release tags without pretending the full
  compatibility platform already exists.

### Negative

- Three tag namespaces instead of one.
- Divergent version numbers require reading each component's release history.
- Someone must remember to push the tag; a merge alone is not a release.

### Risks

- **Backend compatibility regressions** break fielded clients. Mitigation:
  existing contract tests now; deferred `oasdiff` / `asyncapi diff` and
  cross-version CI later; majors require an ADR.
- **`workflow_dispatch` can overwrite `latest`** from a non-`main` branch in
  current backend packaging. Must be fixed in rollout.
- **Fleet drift** if Pis lag. Mitigation: track oldest deployed client version
  operationally; runtime warnings later.
- **Squash-merge titles that are not Conventional Commits** produce poor
  changelogs. Mitigation: keep the existing commit/PR title convention.

## Rollout / Migration

Nothing has been released yet, so there is no migration — only a first cut.

### Beta scope (do soon)

1. Retarget tag triggers: `v*` → `backend-v*` in `docker-ghcr.yml`;
   `locker-client-v*` → `client-v*` in `build-locker-client.yml`; add
   `mobile-v*` to the mobile release path. Tags are expected on commits that
   are on `main`.
2. Gate `latest` on `main` for every push path, including `workflow_dispatch`.
3. Wire `git-cliff` into tag-driven GitHub Releases per component.
4. Document secrets/environments and the maintainer steps: integrate on `dev`,
   merge to `main`, tag on `main`, verify the GitHub Release and artifact.
5. Add a maintainer release checklist under `docs/` (for example
   `docs/release-checklist.md`) that walks through a concrete release: what to
   verify on `dev`, how to merge to `main`, which tag to create, how to confirm
   the GitHub Release and changelog, and how to verify the published artifact
   per component — including which mobile track each step uses (`dev` internal,
   `main` TestFlight/Android beta, tag = versioned release).
6. Wire mobile builds so `dev` produces internal tester builds only, `main`
   can submit to TestFlight / Android beta, and `mobile-v*` tags drive the
   versioned release submission.
7. Cut first beta tags from `main` (for example `backend-v1.0.0-beta.1`,
   `client-v1.0.0-beta.1`, then `mobile-v1.0.0-beta.1`).

### After beta (separate issues)

8. Remove remaining TEMP TestFlight branch exceptions in mobile workflows.
9. Add OpenAPI and AsyncAPI breaking-change checks in CI.
10. Add cross-version integration tests for the support window.
11. Add runtime incompatibility signalling for unsupported client/backend pairs.

Fallback: existing `latest` + SHA publishing continues if tag publishing is
paused.

## Supersedes / Superseded By

- Supersedes: none.
- Related: mobile internal test-build decisions and website deployment stay
  outside or adjacent to this model; website remains continuous deploy from
  `main`.

## References

- Related issues: #50
- Related workflows: `.github/workflows/docker-ghcr.yml`,
  `.github/workflows/build-locker-client.yml`,
  `.github/workflows/mobile-app-build.yml`
- Tools: [Semantic Versioning](https://semver.org/spec/v2.0.0.html),
  [git-cliff](https://github.com/orhun/git-cliff) (monorepo path/tag filters)
