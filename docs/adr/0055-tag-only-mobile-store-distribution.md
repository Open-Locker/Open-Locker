# ADR-0055: Tag-only mobile store distribution

## Status

Accepted

## Date

2026-08-24

## Context

The mobile release workflow selected signed store builds for pushes to `main`.
That made the `dev` to `main` synchronization pull request depend on Apple
Internal Distribution credentials even though no physical iOS test device is
currently available. Signed iOS device and TestFlight validation is tracked for
Beta 2 in issue #242.

Backend and locker-client artifacts are independently versioned. Their first
beta releases must not be blocked by mobile signing work that cannot yet be
validated on a physical device.

Expo documents that an EAS profile with `ios.simulator: true` produces an iOS
Simulator build without an Apple Developer account. EAS CLI also supports
`--non-interactive` for CI. For local builds, EAS packages the simulator
application directory as a `.tar.gz` artifact when writing the selected output;
the workflow must not label that archive as an unpacked `.app` directory.

## Decision

1. Pull request, `dev`, `main`, and `workflow_dispatch` mobile runs use the
   existing Android `preview` profile and a dedicated iOS `ios-simulator`
   profile with `ios.simulator: true`. The local iOS artifact is retained as a
   `.tar.gz` archive containing the Simulator `.app`.
2. Only a pushed `mobile-v*` tag may select the signed `store` profile, submit
   to TestFlight or the configured Android track, and create a mobile GitHub
   Release.
3. A `mobile-v*` tag must still match mobile SemVer and point to the current
   `main` tip. Tagged releases retain the mobile quality gate, version mapping,
   and non-canceling global store concurrency.
4. Manual runs remain preview/simulator-only even when dispatched from a tag.
5. No `mobile-v*` tag is created until issue #242 has provisioned and validated
   signing and physical-device distribution for Beta 2. Backend and
   locker-client beta tags may be created independently before then.

## Alternatives Considered

### Keep signed store builds on `main`

- Pros: exercises store credentials before a mobile tag is created.
- Cons: blocks ordinary branch synchronization on unavailable Apple
  credentials and implies physical-device readiness that has not been tested.
- Why not chosen: store publication must be an explicit mobile release action.

### Skip iOS builds until a physical device is available

- Pros: avoids Apple credentials and macOS build time.
- Cons: removes native iOS compilation coverage from integration branches.
- Why not chosen: simulator builds provide useful unsigned native validation.

## Consequences

### Positive

- Branch and manual CI no longer depend on Apple signing or store submission.
- Backend and locker-client releases can proceed independently.
- Native iOS compilation remains covered through an installable simulator app.

### Negative

- Simulator success does not validate signing, TestFlight, push notifications,
  or behavior specific to physical iOS hardware.
- A mobile release cannot be considered ready until issue #242 is complete.

### Risks

- A future workflow change could accidentally restore store behavior on
  `main`. The structural validator therefore checks the event/profile matrix
  and tag-only submit conditions.
- Apple credential problems may remain undiscovered until Beta 2. Issue #242
  owns explicit provisioning, expiry, physical-device, and submission evidence.

## Rollout / Migration

Add the simulator profile, update workflow selection and artifacts, and rerun
the blocked `dev` to `main` checks. Do not run real EAS builds or submissions
during this migration and do not create a mobile tag.

## Supersedes / Superseded By

- Supersedes: ADR-0043 decision 7 for mobile branch/store mapping and ADR-0032
  decisions 1, 3, and 5 for `main` iOS distribution
- Superseded by: none

## References

- Related issues: #50, #242
- Related ADRs: [ADR-0032](0032-mobile-internal-test-builds-ci.md),
  [ADR-0043](0043-monorepo-release-strategy.md)
- Related files: `.github/workflows/mobile-app-build.yml`,
  `.github/scripts/validate_release_workflows.rb`, `mobile-app/eas.json`
- Expo documentation:
  [iOS Simulator builds](https://docs.expo.dev/build-reference/simulators/),
  [EAS builds on CI](https://docs.expo.dev/build/building-on-ci/)
