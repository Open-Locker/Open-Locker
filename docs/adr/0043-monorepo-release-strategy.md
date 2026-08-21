# ADR-0043: Per-component release strategy for the monorepo

> **Renumbered from ADR-0033** — ADR numbers were deduplicated and put in date order from 0018 up; see #214.

## Status

Proposed

## Date

2026-07-31

## Context

The monorepo holds three components that ship to real users and real hardware:

| Component | Artifact | Destination |
| --- | --- | --- |
| `locker-backend` | Docker image | `ghcr.io/open-locker/locker-backend` → production host |
| `locker-client` | Docker image | `ghcr.io/open-locker/locker-client` → Raspberry Pis in the field |
| `mobile-app` | EAS build | TestFlight / app stores |

`website/` also deploys from the monorepo, but continuously from `main` via GitHub
Pages, with no artifact to roll back to. It is not part of this release model.

The CI half of the problem is already solved. Six workflows exist, each filtered by
path, so a backend change does not run mobile jobs. Both Docker images are built and
pushed to GHCR. The mobile app builds and submits to TestFlight.

What is missing is versioning. **The repository has zero tags and zero GitHub
Releases.** Images are published only as `latest` plus a raw commit SHA. The practical
consequences:

- There is no way to answer "which version is on that Pi", only "which commit", which
  nobody can read.
- There is no way to ask for a specific earlier build, so there is no rollback.
- The one release trigger that does exist (`docker-ghcr.yml` on tags matching `v*`) is
  repo-global, which contradicts releasing components independently. It has never fired.

The components cannot ship together even if we wanted them to. Mobile releases wait on
store review, Pis update on their own schedule, and the backend deploys in a minute.
Any single repo-wide version number would therefore claim a synchronisation that never
exists in practice.

## Decision

**1. Each component is versioned and released independently, under its own tag
namespace.**

```
backend-vX.Y.Z
client-vX.Y.Z
mobile-vX.Y.Z
```

Pushing a tag is what publishes a release. The image or build is labelled with that
version, alongside the existing `latest` and SHA tags.

**2. Version numbers follow SemVer, with both leading digits tied to the contract.**

The contract — the MQTT topics and payloads, and the HTTP API — is the only thing one
component can break in another. The version number is therefore built around it rather
than around how large a change felt:

- **X (major)** — the contract changed in a way that is deliberately **not** backward
  compatible. Requires its own ADR. Expected to be rare, possibly never; the slot costs
  nothing until it is needed.
- **Y (minor)** — the contract changed but stayed **backward compatible**: a new field,
  a new topic, a new endpoint. This is the cross-component signal — when Y moves, the
  other components are worth a look.
- **Z (patch)** — everything else. Features, fixes and refactors that leave the contract
  untouched, however big they are. This is where most releases land.

The consequence is deliberate: a month of new admin screens or mobile UI is a **Z** bump,
because nothing between components moved. Z will therefore grow large (`1.2.38` is
normal and healthy) while Y stays quiet. That is the point — a reader can tell at a
glance whether a release can affect anything outside its own component.

Components start at `1.0.0`, not `0.x`: this software runs on deployed hardware and the
"anything may break" signal of `0.x` would be false.

**3. Releases are cut when enough has accumulated, tagged by hand.**

Merging to `main` does not release. A maintainer merges when ready and then pushes the
tag deliberately, which allows several merges to be batched into one version. There is
no fixed schedule: the team decides a release is due once enough has accumulated to be
worth shipping, roughly monthly in practice. A component with nothing new is simply not
tagged. Urgent fixes go out immediately without waiting for the next batch.

Note that under decision 2 the digit is chosen by *what changed*, not by *how long it
has been*. A batch release is normally a Z bump; it is only a Y if something in the
contract moved during that batch.

**4. The backend is the compatibility authority, and stays backward compatible.**

Older `locker-client` and mobile versions must keep working against a newer backend.
Each component documents only its own minimum supported backend version. We deliberately
do **not** maintain a compatibility matrix — it would be stale within two releases.

**5. `dev` never publishes `latest`.**

`latest` means "current production `main`". If dev-branch artifacts are wanted they are
published under a separate rolling `dev` tag.

**6. Changelogs are generated per component from commit messages.**

Commits already follow Conventional Commits (`fix(backend): …`, `feat(admin): …`) and
reference their issue, so release notes are produced from the log rather than
hand-maintained. Hand-written changelogs rot the moment someone is in a hurry.

Generation is done with a tool that can **filter commits by path** (`git-cliff` or
equivalent), so `locker-client/**` commits appear in the client's notes and not the
backend's. This matters precisely because versions are per component. GitHub's built-in
"generate release notes" is rejected for this reason: it cannot split a monorepo by
component.

The output goes into the **GitHub Release attached to the tag**. No committed
`CHANGELOG.md` is introduced initially — it would duplicate the release notes. One can
be added later per component if someone needs the history offline, for example while
servicing a Pi without network access.

**7. Release notes and contract documentation stay separate concerns.**

They are often confused, so to be explicit:

| Artefact | Answers | Produced by |
| --- | --- | --- |
| Changelog | "What changed since the last version?" | Generated from commits, per release |
| Contract docs | "What does this interface accept today?" | Generated from code, continuously |

Contract documentation already exists and is unaffected by this ADR: Scramble generates
the OpenAPI spec live from the backend controllers and their annotations, and the MQTT
contract lives in `docs/asyncapi/` with contract tests in CI. Code-level annotations
(PHPDoc params, return types, enforced by PHPStan) feed those specs — they are not
release history and are not a substitute for a changelog.

The two meet at exactly one point: under decision 2 a Y bump *is* a contract change, so
the regenerated OpenAPI or AsyncAPI spec is the evidence of what moved. A Y bump with no
spec diff means the digit was chosen wrong — which makes the specs a cheap check on the
version number.

**8. Support window: the current contract line and the one before it.**

Supported means "we will fix bugs against it": the latest Z of the current `X.Y` line,
and the latest Z of the previous one. Because Y moves rarely under decision 2, this is a
deliberately generous window measured in contract generations rather than in releases —
which is the right unit when the thing being supported is a Pi in the field.

Older releases are not supported, but published images are immutable and stay pullable
indefinitely, so rolling back to one remains possible at any time.

## Rationale

Independent versioning is the only honest option, because the three components
physically cannot deploy together. A global version would force a mobile store
submission for a backend-only bugfix, and would still not make the fleet consistent.

Tying both leading digits to the contract makes the number operationally meaningful
rather than decorative. The question an operator actually has is "can this break
something else", and under this scheme the version answers it directly: X or Y moved
means yes, Z means no. Sizing versions by how big a change felt would answer a question
nobody asks — and would hide the one release in twenty that genuinely matters inside a
stream of feature bumps.

The cost is that Z grows large and mobile reads slightly oddly, since a user-facing
release with new screens is "only" a Z. We accept that: the audience for these numbers
is whoever operates the system, not whoever uses the app, and the app stores already
present their own release notes to users.

Keeping the tag as the release trigger, rather than the merge, preserves the distinction
between "this code is on main" and "this is what we are asking people to run". That
distinction is what makes rollback meaningful.

The existing workflows already do most of the mechanical work, so this decision is
mostly about naming and policy rather than new pipelines.

## Alternatives Considered

### Alternative A: A single repo-wide version (`vX.Y.Z`)

- Pros: one number to think about; matches the `v*` trigger already present in
  `docker-ghcr.yml`; no ambiguity about which versions belong together.
- Cons: every component bumps when any one changes, so a backend patch would push a new
  mobile build through store review; the shared number implies a lockstep deployment
  that never happens.
- Why not chosen: the implied synchronisation would be false, and mobile store friction
  makes the cost concrete rather than theoretical.

### Alternative B: Release automatically on every merge to `main`

- Pros: no manual step to forget; `main` and the released version never drift.
- Cons: no way to batch changes into a coherent version; every merge consumes a version
  number and, for mobile, a store submission.
- Why not chosen: releasing should stay a deliberate act, especially for artifacts that
  reach hardware we cannot easily touch.

### Alternative C: Size versions by the scale of the change

Y for "a batch of new features", Z for "fixes" — the everyday reading of SemVer for
applications.

- Pros: familiar to anyone who has not thought about it hard; the version grows in a way
  that matches how much work went in; reads naturally for a user-facing app.
- Cons: Y stops carrying information, because it moves for reasons that cannot affect
  anyone else; the one release that genuinely changes the contract looks identical to
  the twenty that do not.
- Why not chosen: for a system with hardware in the field, the only question the number
  needs to answer is "can this break something else". Sizing by scale answers a
  different question, and buries the one that matters.

### Alternative D: Maintain a compatibility matrix across components

- Pros: precise answer to "does this client work with that backend".
- Cons: needs updating on every release of every component; goes stale silently, and a
  stale matrix is worse than none.
- Why not chosen: backward compatibility as a backend rule achieves the same guarantee
  with no upkeep.

## Consequences

### Positive

- "Which version is deployed" becomes answerable, for the backend and for each Pi.
- Rollback becomes possible: an earlier image tag can simply be pulled.
- Components stop blocking each other's release cadence.
- Release notes come for free from commit messages already being written.

### Negative

- Three tag namespaces to remember instead of one.
- Z inflates. Version numbers like `backend-v1.2.38` are normal here and will look
  unfamiliar to anyone expecting version size to track effort.
- Mobile reads oddly: a release full of new screens is a Z bump, because nothing between
  components moved.
- Determining which versions of different components were current at a given time
  requires reading the release history rather than a single number.
- Someone must remember to push the tag; a merged fix is not a released fix.

### Risks

- **Backward compatibility is now a standing obligation on the backend.** If it is
  broken accidentally, fielded Pis fail with no local fix. Mitigation: the MQTT and
  HTTP contracts are already covered by contract tests, and an X bump requires an ADR.
- **The digit depends on judgement about the contract.** Someone shipping a contract
  change as a Z bump silently removes the signal the scheme exists for. Mitigation: a Y
  bump should show a matching OpenAPI or AsyncAPI diff, which makes the two checkable
  against each other.
- **Fleet drift.** Independent versioning makes it easier for Pis to fall far behind.
  Mitigation: track the oldest `locker-client` version still deployed.
- **`workflow_dispatch` on `docker-ghcr.yml` currently pushes `latest` from whichever
  branch it runs on**, so a manual dispatch from `dev` would silently overwrite the
  production tag. This must be fixed as part of rollout.

## Rollout / Migration

Nothing has ever been released, so there is no migration — only a first release.

1. Retarget the tag triggers: `v*` → `backend-v*` in `docker-ghcr.yml`, and add
   `client-v*` to `build-locker-client.yml`.
2. Gate `latest` on `main` in every push path, including `workflow_dispatch`.
3. Add `workflow_dispatch` to the locker-client workflow, and a tag trigger to the
   mobile workflow.
4. Remove the `TEMP` condition in `mobile-app-build.yml` that allows TestFlight
   submission from `feat/19-mobile-internal-builds`.
5. Add per-component changelog generation from Conventional Commits (`git-cliff` or
   equivalent), filtered by path so each component's notes contain only its own commits,
   written into the GitHub Release for the tag.
6. Document the required secrets (`EXPO_TOKEN`, the Apple ASC key held on EAS,
   `GITHUB_TOKEN`, and whatever credentials the backend host uses to pull the image)
   and how to cut and verify a release.
7. Cut `backend-v1.0.0` and `client-v1.0.0` first — they share a workflow shape and
   prove the scheme cheaply. `mobile-v1.0.0` follows once the convention has settled,
   since store submission is the least forgiving place to discover a mistake.

Fallback: the tag triggers are additive. If the scheme proves wrong, the existing
`latest` + SHA publishing continues to work untouched.

## Supersedes / Superseded By

- Supersedes: none.
- Related: ADR-0033 (mobile internal test builds) explicitly deferred public release
  strategy to this decision. ADR-0038 covers website deployment, which stays outside
  this model.

## References

- Related issues: #50, #19 (mobile store release), #102 (dev-branch CI)
- Related ADRs: ADR-0033, ADR-0038
- Related workflows: `.github/workflows/docker-ghcr.yml`,
  `.github/workflows/build-locker-client.yml`, `.github/workflows/mobile-app-build.yml`
