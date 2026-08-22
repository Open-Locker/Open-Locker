# ADR-0047: Ship internal Android builds for physical-device ABIs only

> **Renumbered from ADR-0035** — ADR numbers were deduplicated and put in date order from 0018 up; see #214.

## Status

Proposed

## Date

2026-08-12

## Context

The Android job in `mobile-app-build.yml` takes about 23 minutes, against roughly nine
for iOS. Issue #204 assumed the difference was Gradle and EAS cache misses. Profiling the
last successful run (`28397897877`) shows that assumption is wrong, and by a wide margin.

Every step outside `eas build --local` — checkout, pnpm, `setup-java`, `pnpm install` —
totals **16 seconds**. Of the 23m30s job, ~1.9m is pre-Gradle work (project compression,
prebuild, eager bundle) and ~21.6m is Gradle. Gradle's own task-timing table attributes
that time to three things:

1. **Native C++ builds across four ABIs.** `arm64-v8a`, `armeabi-v7a`, `x86`, `x86_64`
   are each compiled from source for `expo-modules-core`, `react-native-reanimated`,
   `react-native-worklets`, `react-native-screens` and `:app`. The four longest
   wall-clock gaps in the entire run were per-ABI Reanimated builds (103s, 83s, 74s,
   73s). The two emulator-only ABIs account for roughly **6–7 minutes**.
2. **`lintVitalAnalyzeRelease` on every module** — safe-area-context 47s, expo 26.5s,
   gesture-handler 23.2s, svg 20.6s, netinfo 19.9s. Roughly **2.5–3 minutes** spent
   producing a lint report that no one reads, on an internal test build.
3. **Kotlin/Java compilation** of the React Native libraries —
   `react-native-screens:compileReleaseKotlin` alone is 59.5s.

Downloads and toolchain installs — the Gradle distribution zip, NDK 27.1, CMake 3.22.1,
a 44s cold daemon start — come to **3–4 minutes**. That is the entire share of the build
that a dependency cache can address. Notably, there is no R8/minification step in the
build at all, so shrinking configuration is not a lever here.

The build also resolved `eas-cli@latest` on every run, at three call sites. A release
tool that changes without a commit is a reproducibility problem independent of speed.

## Decision

Optimise for what the profile actually shows, in this order.

**Build only the ABIs that ship to physical devices.** The production Android profile
passes `-PreactNativeArchitectures=armeabi-v7a,arm64-v8a`. `x86` and `x86_64` exist for
emulators; internal builds are distributed to real test devices, and Play Store delivery
to physical hardware never uses them. This is the single largest saving and the only one
that changes the artifact.

**Skip lint on release builds** via `-x :app:lintVitalRelease`. Lint belongs in
`mobile-app-ci.yml`, which runs on every push and is where a developer would actually
read the result — not in the artifact-producing job.

**Enable the Gradle build cache** (`--build-cache`) and persist `~/.gradle/caches` and
`~/.gradle/wrapper` between runs with `actions/cache`. This covers dependency downloads
*and* task outputs keyed by input hash, so a warm run skips recompiling unchanged native
and Kotlin code rather than merely skipping downloads. The cache key carries
`pnpm-lock.yaml`, `app.config.ts` and `eas.json`, so a native dependency change cannot
restore stale outputs.

**Pin the EAS CLI** to an explicit version in a workflow-level `EAS_CLI_VERSION`.

Deliberately *not* done: caching the generated `android/` prebuild directory, which issue
#204 proposed. Expo regenerates it from config on every build, and a restored copy is the
most likely source of a build that succeeds while being subtly wrong. The Gradle build
cache gets the same benefit with input-hash correctness.

Signing behaviour is unchanged: credentials are still fetched from EAS at build time via
`EXPO_TOKEN`, as decided in ADR-0033.

## Alternatives Considered

**Cache dependencies only, as issue #204 specified.** Safe and artifact-neutral, but the
profile caps it at 3–4 minutes of 23. It would have shipped as a completed ticket that
left the actual cost untouched.

**Move to EAS cloud builds** instead of `--local`. Removes the runner from the critical
path but reintroduces the build-minute cost that ADR-0033 chose local builds to avoid.

**Build a single ABI (`arm64-v8a`).** Faster still, but `armeabi-v7a` is cheap relative
to the emulator ABIs and dropping it would exclude older 32-bit test hardware.

**Keep all four ABIs and accept the time.** Defensible while nobody waits on the Android
job — it is triggered by push to `main` and by manual dispatch, not by PR review. Rejected
because the emulator ABIs are pure waste for a device-distributed artifact.

## Consequences

The production Android APK no longer installs on x86/x86_64 Android emulators. Anyone
testing on an emulator must build locally (`pnpm android`) or use an arm64 emulator image,
which is the default on Apple Silicon. If emulator-installable internal artifacts become a
requirement, the fix is a separate build profile that keeps all four ABIs, not reverting
this one.

Android lint no longer runs anywhere in CI. `mobile-app-ci.yml` covers TypeScript
(`typecheck`, `expo lint`, `format:check`, Jest, `expo-doctor`) but never invoked AGP's
`lintVital`, which checks a different class of problem: manifest errors, resource
problems, missing translations, unsafe API levels. Skipping it in the build removes that
coverage rather than relocating it. It was previously running only at artifact-build time
on `main`, where nobody read the report; if the coverage is wanted back, the right home is
a `lintRelease` step in `mobile-app-ci.yml` that runs per pull request.

Warm runs depend on cache restoration. A cold run — first build after a dependency change,
or after the 7-day GitHub cache eviction — still pays the download and full-compile cost.
Measured on the Android job: 22m26s before (mean of two runs, 6.3% spread), 14m23s after
on a cold cache, 10m25s after on a warm one.

The pinned EAS CLI version must now be bumped deliberately. That is the intent, but it
adds a maintenance step that will otherwise drift.

## References

- Issue #204 — Cache Android dependencies in local EAS builds
- ADR-0033 — Mobile internal test builds in CI (`0032-mobile-internal-test-builds-ci.md`)
- Profiled run: GitHub Actions run `28397897877`, `mobile-app-build.yml`
- `mobile-app/eas.json`, `.github/workflows/mobile-app-build.yml`
