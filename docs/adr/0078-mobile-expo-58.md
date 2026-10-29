# ADR-0078: Adopt Expo SDK 58

## Status

Accepted

## Date

2026-10-29

## Context

[ADR-0067](0067-mobile-expo-57-ios-support.md) adopted Expo SDK 57. Issue #319
held three mobile security advisories on that SDK and decided to upgrade once
SDK 58 was stable:

- `decode-uri-component` (GHSA-vcc3-ghjq-m6fr) is reached through Expo Router
  57's `query-string` 7 parser. The fixed decoder breaks that parser's CommonJS
  contract.
- `braces` (GHSA-vfj7-8cjw-p6xm) is reached through Jest and Metro.
- `node-forge` (GHSA-86w9-cpqp-85rv) is reached through Expo's code-signing
  package.

SDK 58.0.0 became the stable `latest` release on 2026-09-29. Its platform floor
matches SDK 57: iOS 16.4+, Xcode 26.4+, Android 7+, Node 22.13+. Only the
Android compile SDK rises, from 36 to 37.

## Decision

Adopt Expo 58.0.21 with its aligned dependency set: React Native 0.88.1,
React 19.3.0, Expo Router 58.0.28, Jest 30 with jest-expo 58 and Testing
Library 14. Keep the iOS 16.4+ support decision from ADR-0067. Keep TypeScript
5.9.3.

Expo Router 58 parses query strings with the platform `URL.searchParams`, which
removes `query-string` and `decode-uri-component` from the app.

Keep `@expo/metro-runtime` as a direct dependency, because pnpm otherwise
resolves a stale version that Expo Router's peer range rejects.

## Alternatives Considered

- **Stay on SDK 57 and override `decode-uri-component`:** this breaks deep-link
  decoding with `decodeComponent is not a function`, so it was rejected in #319.
- **Upgrade after PR #318 merges:** this was #319's original plan. The upgrade
  moved into #318 so that the app ships without the parser advisory and the
  native builds are validated only once.

## Consequences

- `decode-uri-component` and `query-string` are gone from the lockfile.
  `node-forge` resolves to 1.4.2, which is outside the advisory's `<= 1.4.0`
  range. The `braces` advisory was withdrawn on 2026-10-23.
- Two advisories remain, both inside Expo CLI 58 tooling: `zod` 3.25.76
  (GHSA-mrhx-c7xc-m3w5), because `@expo/cli` pins `^3.25.76`, and `deepmerge`
  4.3.1 (GHSA-gcqx-w48m-w2h4), which has no patched release.
- `render` and `renderHook` from Testing Library 14 return Promises, so the
  tests await them. React Native 0.88's `useColorScheme()` can return `null`,
  and the theme helpers accept it.
- The `expo-status-bar` config plugin is required.
- Fresh native builds are required, and an OTA update cannot deliver this
  change. Android release builds now minify with R8 by default, so a native
  device smoke test must confirm that no library loses reflection targets.

## References

- [Issue #319](https://github.com/Open-Locker/Open-Locker/issues/319)
- [ADR-0067](0067-mobile-expo-57-ios-support.md), superseded by this ADR
- [Expo SDK 58 changelog](https://expo.dev/changelog/sdk-58)
- [Expo SDK version and platform table](https://docs.expo.dev/versions/latest/)
- [GHSA-vfj7-8cjw-p6xm (withdrawn)](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm)
- [GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv)
