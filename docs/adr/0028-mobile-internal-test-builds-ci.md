# ADR-0028: Mobile internal test builds via GitHub Actions + `eas build --local`

## Status

Proposed

## Date

2026-06-26

## Context

The mobile app (`mobile-app/`, Expo/React Native) had no automated build
pipeline. The team needs a repeatable way to produce **internal test builds**
(Android + iOS) so testers can install the app on devices, triggered on merges
to `main`. Public App Store / Play Store release is explicitly **out of scope**
(see issue #19; broader release strategy is #50, dev-branch CI is #102).

Existing groundwork already in the repo:

- `eas.json` with `development` / `preview` / `production` profiles.
- `app.config.ts` with per-variant app name/identifier handling. It **throws**
  for the `production` variant unless `APP_ID_BASE` is provided.
- An EAS project (`@merona-apps/open-locker-mobile`) with managed signing
  credentials already provisioned (Android keystore; iOS distribution cert,
  provisioning profile, and App Store Connect API key).

Constraints / drivers:

- The repo is open source → GitHub Actions minutes are free, including macOS
  runners. The team prefers to "run most things on GitHub."
- Hard requirement from the team: **no signing credentials in the repo.**
- Chef preference: use the **production** build profile so the same artifact can
  later be promoted to a store without rebuilding.

## Decision

1. Add a GitHub Actions workflow (`.github/workflows/mobile-app-build.yml`) that,
   on **push to `main`** touching `mobile-app/**` (plus manual `workflow_dispatch`),
   builds the app for Android and iOS.
2. Builds run with **`eas build --local`** on GitHub runners
   (`ubuntu-latest` for Android, `macos-latest` for iOS) — not on EAS's cloud.
3. Use the **`production`** build profile, with `APP_ID_BASE=de.merona.openlocker`
   supplied as a non-secret workflow env var.
4. Signing credentials are **fetched from EAS at build time** via an `EXPO_TOKEN`
   GitHub secret (`credentialsSource: remote`). The keystore/cert are therefore
   **not** stored as GitHub secrets and **never** enter the repo. Local developer
   copies (`credentials.json`, `credentials/`) are gitignored.
5. Distribution to testers: Android via the build artifact / internal install;
   iOS via TestFlight (the existing iOS credentials are App Store type).

## Rationale

- `--local` on GitHub keeps builds on free public-repo minutes and matches the
  team's "run on GitHub" preference, instead of consuming the EAS build quota.
- Fetching credentials from EAS at build time means the only secret is
  `EXPO_TOKEN`; signing material stays on EAS and out of both the repo and the
  CI secret store — directly satisfying the "no credentials in repo" rule with
  minimal secret handling.
- The `production` profile keeps the artifact promotable later, per the chef's
  build-once preference, without committing to a store release now.

## Alternatives Considered

### Alternative A: Remote `eas build` triggered from GitHub Actions

- Pros: simplest; credentials never leave EAS; no runner toolchain setup.
- Cons: builds consume the EAS build quota rather than free GitHub minutes;
  weaker fit for "run on GitHub."
- Why not chosen: team preference to run builds on GitHub's free infra. Retained
  as the documented fallback if `--local` credential fetch proves unreliable.

### Alternative B: `preview` profile for internal builds

- Pros: already `distribution: internal`; no `APP_ID_BASE` guard.
- Cons: artifact is not the same one promoted to a store later.
- Why not chosen: chef wants production artifacts that can be promoted.

### Alternative C: Inject base64 credentials as GitHub secrets

- Pros: fully self-contained CI; no runtime dependency on EAS credential fetch.
- Cons: more secrets to manage and rotate; more credential handling surface.
- Why not chosen: `EXPO_TOKEN` + remote fetch is simpler and safer. Kept as the
  fallback if non-interactive remote fetch fails (see workflow open questions).

## Consequences

### Positive

- Push to `main` produces installable internal builds with no manual steps.
- One secret (`EXPO_TOKEN`); signing material stays on EAS, never in the repo.
- Production artifacts remain promotable to stores later.

### Negative

- iOS local builds run on `macos-latest` (10x minute weight) and are slow.
- `eas build --local` requires a build toolchain on the runner (Java for Android).

### Risks

- **iOS cert + provisioning profile expire 2026-07-13.** EAS auto-renews on the
  next build; the first build after expiry triggers renewal. Mitigation: build
  regularly / be aware around mid-July 2026.
- If `--local` cannot fetch remote credentials non-interactively, fall back to
  Alternative C. Mitigation documented in the maintainer guide.
- macOS minutes could hit the monthly cap with frequent merges. Mitigation:
  path-filtered trigger; revisit cadence if needed.

## Rollout / Migration

1. Add the `EXPO_TOKEN` secret (scoped to `merona-apps`) in the GitHub repo.
2. Merge the workflow; trigger via `workflow_dispatch` to validate before relying
   on `main` pushes.
3. Confirm artifacts build for both platforms; have ≥1 tester install a build.
4. Resolve open questions in the workflow (Android `.aab` vs `.apk`; iOS
   TestFlight upload vs ad-hoc) once the first builds are green.

## Supersedes / Superseded By

- Supersedes: none
- Superseded by: none

## References

- Related issues: #19 (this work), #50 (release strategy), #102 (dev-branch CI)
- Related docs: `mobile-app/docs/internal-builds.md` (maintainer guide)
- Related files: `.github/workflows/mobile-app-build.yml`, `mobile-app/eas.json`,
  `mobile-app/app.config.ts`
