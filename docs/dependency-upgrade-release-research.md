# Dependency upgrade release research: backend and mobile

Research date: 2026-10-06, with implementation review on 2026-10-07. Companion to `dependency-upgrade-plan.md`, which records implemented versions, checks and remaining limits. Candidate tables preserve the initial research snapshot; the lockfiles and implementation record are authoritative for the resolved result.

## Scope and evidence

[#305](https://github.com/Open-Locker/Open-Locker/issues/305) requests compatible Composer updates, preserved backend behavior/API contracts, `composer quality`, and follow-up issues for substantial migrations. [#304](https://github.com/Open-Locker/Open-Locker/issues/304) requests the latest stable Expo supported by the project, SDK alignment, native checks, `pnpm check` and `pnpm test:ci`; product decisions or substantial refactors can be deferred with a follow-up.

Current versions come from `locker-backend/composer.lock` and both modules' `pnpm-lock.yaml` importers, rather than manifest minimums. Backend `AGENTS.md` describes Livewire 3, but the actual lock already contains Livewire **4.4.3** and Filament **5.7.8**. No fresh Filament 4→5 or Livewire 3→4 migration is needed.

Version/requirement observations use the primary [Packagist metadata API](https://repo.packagist.org/p2/laravel/framework.json) and [npm registry](https://registry.npmjs.org/expo). Other packages use the same endpoints with their package name substituted. Expo's immutable published compatibility lists are [55.0.31](https://unpkg.com/expo@55.0.31/bundledNativeModules.json) and [57.0.26](https://unpkg.com/expo@57.0.26/bundledNativeModules.json). These provide the package groups below; do not replace SDK-managed versions with independent npm `latest` versions.

## Backend: recommended sequence and candidates

Update within Laravel 12 and the existing PHPUnit 11 family, matching #305's compatible, non-breaking scope. Keep Tinker 2. No Laravel 13 or PHPUnit major migration is scheduled. Keep TypeScript 5.9.3 in both TypeScript modules and retain runtime/package-manager versions unless the issue-scoped dependency updates require a compatibility change.

| Direct Composer package | Locked | Planned candidate |
| --- | --- | --- |
| PHP platform | Manifest `^8.2`; Docker/CI 8.4 | Keep deployed 8.4 |
| laravel/framework | 12.62.0 | 12.69.3 |
| dedoc/scramble | 0.13.25 | 0.13.47 |
| filament/filament | 5.7.8 | 5.9.0 |
| keepsuit/laravel-opentelemetry | 2.2.4 | 2.2.6 |
| laravel-lang/common | 6.8.0 | 6.8.0 |
| laravel/reverb | 1.8.0 | 1.12.0 |
| laravel/sanctum | 4.3.1 | 4.3.3 |
| laravel/tinker | 2.11.1 | 2.11.1 |
| php-mqtt/laravel-client | 1.7.0 | 1.8.0 |
| spatie/laravel-event-sourcing | 7.15.0 | 7.15.1 |
| brianium/paratest | 7.8.5 | **7.8.6** |
| fakerphp/faker | 1.24.1 | 1.24.1 |
| larastan/larastan | 3.9.2 | 3.12.3 |
| laravel/boost | 2.2.0 | 2.10.2 |
| laravel/pail | 1.2.6 | 1.2.7 |
| laravel/pint | 1.27.1 | 1.32.1 |
| laravel/sail | 1.53.0 | 1.68.0 |
| mockery/mockery | 1.6.12 | 1.6.15 |
| nunomaduro/collision | 8.9.1 | 8.9.5 |
| opis/json-schema | 2.6.0 | 2.6.0 |
| phpunit/phpunit | 11.5.55 | **11.5.57** |
| ta-tikoma/phpunit-architecture-test | 0.8.7 | 0.8.7 |

Transitive Livewire candidate: **4.4.3→4.4.7**, keeping Filament 5's Livewire-4 requirement. Candidates remain subject to Composer resolution. Pint 1.32.1 requires PHP 8.3; reconcile the declared platform requirement with the deployed PHP 8.4 runtime, or keep a PHP-8.2-compatible Pint if PHP 8.2 support must remain.

### Release notes and concrete applicability

- [Laravel 12.69.3 notes](https://github.com/laravel/framework/releases/tag/v12.69.3): worker exit handling changes deserve worker smoke coverage even in the initial group. Review all intervening Laravel-12 notes during the solved update, not only the final tag.
- [Filament 5 requirements](https://filamentphp.com/docs/5.x/upgrade-guide) confirm Livewire 4 and Tailwind 4. [5.9.0 notes](https://github.com/filamentphp/filament/releases/tag/v5.9.0) cover action, schema, table, editor and navigation changes. Run existing Filament admin/resource/authorization tests and manually exercise edit modals and table filters. [Livewire 4 upgrade guide](https://livewire.laravel.com/docs/4.x/upgrading) includes hashed asset/update routes; major 4 is already installed, so treat this as an audit of an existing migration, particularly proxy routing and cached assets. [4.4.7 comparison](https://github.com/livewire/livewire/compare/v4.4.3...v4.4.7) provides patch-range source review.
- [Spatie 7.15.1](https://github.com/spatie/laravel-event-sourcing/releases/tag/7.15.1) changes the reflection-docblock constraint for Laravel 13/Symfony 8. No event-stream rewrite is described; keep persisted event names/serialization unchanged and test replay/projector/reactor paths.
- [Scramble releases](https://github.com/dedoc/scramble/releases) include accessor/resource/type inference and route-cache generation fixes across this range. Diff live `/docs/api.json` before/after, regenerate mobile RTK Query against the updated backend, and review unintended schema changes.
- [Telemetry 2.2.6](https://github.com/keepsuit/laravel-opentelemetry/releases/tag/2.2.6) fixes sampling with remote trace parents. [Reverb 1.12.0](https://github.com/laravel/reverb/releases/tag/v1.12.0) closes failed handshakes and fixes scaled presence. [MQTT client notes](https://github.com/php-mqtt/laravel-client/releases) add Laravel/PHP support declarations. Validate MQTT provision/reconnect/command acknowledgements and mobile realtime recovery.
- [Larastan 3.12.3](https://github.com/larastan/larastan/releases/tag/v3.12.3) improves collection/relation/property inference. Fix actual typing errors rather than expanding the baseline blindly.
- [ParaTest metadata](https://repo.packagist.org/p2/brianium/paratest.json) shows that latest 7.26.0 requires PHPUnit 13.4 despite remaining in major 7. Keep 7.8.6 with PHPUnit 11.5.57; document the compatible hold instead of introducing a test-runner major migration.

The initial research deferred the remaining tooling release intervals until resolution. The implementation review below records the subsequently reviewed upgrades and transitive requirements. Packages retained at their original locked versions have no upgrade interval. Patch/minor numbering alone is not evidence that behavior is unchanged.

### Implementation review on 2026-10-07

Filament **5.10.0**, published after the original inventory, is the selected compatible target. The [5.10.0 notes](https://github.com/filamentphp/filament/releases/tag/v5.10.0) include stricter initial widget/relation-manager and nested ancestor authorization, guard-scoped authentication, relationship action failures, file-upload naming, persisted table state isolation and immutable date/time handling. Review these against the existing admin/resource tests; keep published frontend assets synchronized in a separate commit.

The intervening [Laravel 12 notes](https://github.com/laravel/framework/releases) include stricter `in` validation, temporary upload query handling, recaller password validation, PostgreSQL JSON escaping and queue timeout exit codes. They require behavior checks, but no framework-major migration. Composer's security policy also selects CommonMark 2.10.3 and Flysystem 3.36.0 during the Laravel update.

[Scramble 0.13.43](https://github.com/dedoc/scramble/releases/tag/0.13.43) explicitly changes homogeneous unions from `anyOf` to OpenAPI 3.1 type arrays, removes invalid `additionalItems` and its setter, and stops treating plain return comments as response descriptions. Check the generated schema and RTK Query output rather than assuming a minor update is invisible.

[Boost's upgrade guide](https://github.com/laravel/boost/blob/v2.10.2/UPGRADE.md) documents the 2.5 guideline authoring API change from Roster enums to PackageRegistry/ProjectManager. This repository has no `.ai` overrides using the removed API. Its 2.2.1 Inertia guideline path migration is also inapplicable. Updating Boost does not require regenerating repository agent instructions.

[Larastan's notes](https://github.com/larastan/larastan/releases) cover migration caching enabled by default, PHPStan 2.2.14 minimum, model serialization shapes and tighter relation/config inference. Preserve existing analysis gates and fix real diagnostics. [Pint's notes](https://github.com/laravel/pint/releases) include fully-qualified type formatting and Blade fixes; review formatter churn separately. Sail changes package repositories and container installation scripts, so container rebuilding remains required even when native PHP checks pass. Pail fixes malformed JSON handling and avoids resolving the auth user while logging. Mockery fixes partial constructor/clone handling and negative expectations. Collision's compatible changes affect test recap boundaries. PHPUnit 11.5.56–57 and ParaTest 7.8.6 mainly adjust PHP 8.6 support and PHAR metadata; retain PHPUnit 11.

Expo **57.0.27** is the final patch target, with Constants 57.0.21, Linking 57.0.12 and Router 57.0.25. The [immutable Expo changelogs](https://github.com/expo/expo/tree/50ac74f1ab5682c8e32b24da6e5e97dd4dbbb80f/packages) describe platform-route parsing fixes, listener garbage collection, iOS reload/pod-install fixes, Android layout fixes and CLI cross-origin/prototype guards. Metro's file-map package removes its `braces` dependency; Jest still requires checking separately. No new compiler upgrade is required.

## Mobile: target and product support gate

The latest stable observed SDK is **57.0.26**, not 55 or 56; npm `next` is 58.0.5 and is not the selected stable channel. Upgrade in checkpoints **54→55→57**, reading and applying the SDK-56 migration between checkpoints. Do not ship SDK 56 because the documented Hermes/worklets memory regression directly affects this app's imported Reanimated/worklets. SDK 57 fixes it.

SDK 56/57 raises the iOS minimum from **15.1 to 16.4**. This is a support/product decision under #304. Use **55.0.31 as the compatible fallback** until that decision is recorded; if iOS 16.4+ is accepted, complete #304 on 57.0.26. Only the iOS-support decision is a known substantial gate; the identified Router import changes are straightforward.

### SDK-managed set

These targets are the exact requirements in the published Expo packages, rather than independent latest versions. `~` ranges will resolve to compatible patches at implementation.

| Package | Locked on SDK 54 | SDK 55 fallback | SDK 57 stable target |
| --- | --- | --- | --- |
| expo | 54.0.37 | 55.0.31 | 57.0.26 |
| expo-constants | 18.0.14 | ~55.0.17 | ~57.0.20 |
| expo-font | 14.0.12 | ~55.0.8 | ~57.0.4 |
| expo-linking | 8.0.12 | ~55.0.17 | ~57.0.11 |
| expo-localization | 17.0.9 | ~55.0.19 | ~57.0.2 |
| expo-router | 6.0.24 | ~55.0.18 | ~57.0.24 |
| expo-secure-store | 15.0.8 | ~55.0.18 | ~57.0.4 |
| expo-splash-screen | 31.0.13 | ~55.0.25 | ~57.0.9 |
| expo-status-bar | 3.0.9 | ~55.0.6 | ~57.0.1 |
| expo-system-ui | 6.0.9 | ~55.0.22 | ~57.0.4 |
| expo-web-browser | 15.0.11 | ~55.0.20 | ~57.0.3 |
| react / react-dom | 19.1.0 | 19.2.0 | 19.2.3 |
| react-native | 0.81.5 | 0.83.10 | 0.86.3 |
| react-native-gesture-handler | 2.28.0 | ~2.30.0 | ~2.32.0 |
| react-native-reanimated | 4.1.6 | 4.2.1 | 4.5.1 |
| react-native-worklets | 0.5.1 | 0.7.4 | 0.10.1 |
| react-native-screens | 4.16.0 | ~4.23.0 | ~4.26.0 |
| react-native-safe-area-context | 5.6.2 | ~5.6.2 | ~5.7.0 |
| react-native-svg | 15.12.1 | 15.15.3 | 15.15.4 |
| react-native-web | 0.21.2 | ~0.21.0 (latest 0.21.3) | ~0.21.0 (latest 0.21.3) |
| @react-native-community/netinfo | 11.4.1 | 11.5.2 | 12.0.1 |
| @expo/vector-icons | 15.0.3 | ^15.0.2 (latest 15.1.1) | ^15.0.2 (latest 15.1.1) |
| eslint-config-expo | 10.0.0 | ~55.0.1 | ~57.0.2 |
| jest-expo | 54.0.18 | ~55.0.22 | ~57.0.5 |

### Other direct mobile dependencies

| Package | Locked | Candidate and rationale |
| --- | --- | --- |
| @expo-google-fonts/inter | 0.4.2 | Keep 0.4.2; latest observed |
| @gorhom/bottom-sheet | 5.2.8 | 5.2.14; verify gesture/keyboard on selected Reanimated pair |
| @react-navigation/native | 7.1.28 | SDK 55: 7.5.0; SDK 57: remove direct dependency after migrating imports |
| @reduxjs/toolkit | 2.11.2 | 2.13.0 |
| react-redux | 9.2.0 | 9.3.0 |
| i18next | 25.8.13 | 26.4.2 bounded candidate; fallback 25.10.10 if migration unexpectedly grows |
| react-i18next | 16.5.4 | 17.0.15 with i18next candidate; fallback 16.6.6 with i18next≥25.10.9 |
| lucide-react-native | 0.575.0 | 1.52.0 bounded candidate; preserve icons/a11y; never use accidentally published 1.0.0 |
| laravel-echo | 2.3.7 | 2.5.0; investigate existing Metro ESM override before removing it |
| pusher-js | 8.5.0 | 8.6.0 |
| react-native-paper | 5.15.0 | 5.15.3 |
| react-native-render-html | 6.3.4 | Keep 6.3.4; latest observed; verify with React 19.2/native changes |
| @rtk-query/codegen-openapi | 2.2.0 | Keep 2.2.0; latest observed; regenerate/diff contract |
| @testing-library/react-native | 13.3.3 | Keep 13.3.3; compatible with the selected Expo test preset |
| @types/jest | 29.5.14 | Keep 29.5.14 with Jest 29 |
| @types/react | 19.1.17 | 19.2.18 after selected React 19.2 alignment |
| eslint | 9.39.2 | 9.39.5; retain the compatible major |
| eslint-config-prettier | 10.1.8 | Keep 10.1.8; latest observed |
| eslint-plugin-prettier | 5.5.5 | 5.5.6 |
| jest | 29.7.0 | Keep 29.7.0; current jest-expo 55/57 still internally uses Jest-29 packages |
| prettier | 3.8.1 | 3.9.9; inspect formatting churn |
| react-native-svg-transformer | 1.5.3 | Keep 1.5.3; latest observed |
| typescript | 5.9.3 | Keep 5.9.3; no compiler upgrade required |

### Release notes and concrete migrations

- [SDK 55](https://expo.dev/changelog/sdk-55): New Architecture is mandatory; SDK packages switch to SDK-numbered majors; Android edge-to-edge/status-bar behavior changes. `app.config.ts` already sets `newArchEnabled: true` and `edgeToEdgeEnabled: true`; preserve behavior and remove unsupported config only after checking selected SDK types. The app uses safe-area-context instead of deprecated RN SafeAreaView. Unused media/calendar/UI migrations are not work items for this app.
- [SDK 56](https://expo.dev/changelog/sdk-56): iOS 16.4 and Xcode 26.4 are required; fetch implementation changes and Router packaging affect this code. EAS profiles currently do not pin an image. Verify resolved build images, device support and API error/retry behavior. No file-system `copy/move` or custom Expo UI imports were found in targeted searches.
- [Router 55→56 guide](https://docs.expo.dev/router/migrate/sdk-55-to-56/): replace `@react-navigation/native` imports with `expo-router/react-navigation`. Actual call sites are `app/_layout.tsx` (`ThemeProvider`) and `src/theme/themeFactory.ts` (`DarkTheme`, `DefaultTheme`, `Theme`). APIs are unchanged; this is a bounded import migration. Remove the unused direct navigation dependency afterward and check authenticated/deep-link/back navigation.
- [SDK 57](https://expo.dev/changelog/sdk-57): React Native 0.86.3 and Expo≥57.0.17 fix inherited Hermes memory and dev-startup regressions. Use 57.0.26 with its bundled animation packages. Prebuild now cleans/regenerates native directories by default: preserve any native customizations before regeneration. If builds use Xcode 27, follow its opt-in scene-support configuration rather than assuming Xcode-26 setup suffices.
- [RN 0.82](https://reactnative.dev/blog/2025/10/08/react-native-0.82) requires New Architecture, already enabled. [RN 0.84](https://reactnative.dev/blog/2026/02/11/react-native-0.84) defaults Hermes V1. [RN 0.85](https://reactnative.dev/blog/2026/04/07/react-native-0.85) introduces a new Jest preset package; `jest-expo@57.0.5` declares `@react-native/jest-preset:^0.86.3` as peer, so ensure it resolves. [Reanimated compatibility](https://docs.swmansion.com/react-native-reanimated/docs/guides/compatibility/) reinforces keeping Reanimated/worklets/RN aligned.
- [NetInfo 12](https://github.com/react-native-netinfo/react-native-netinfo/releases/tag/v12.0.0) changes iOS Wi-Fi information API and minimum iOS14. This floor is below the selected SDK floor, and connection monitoring here uses connectivity state; validate offline/reconnect rather than introducing new Wi-Fi entitlements unnecessarily.
- [i18next 26 migration](https://www.i18next.com/misc/migration-guide) removes legacy formatting, `initImmediate`, and support-notice options. None is configured in `src/i18n/index.ts`; ordinary `useTranslation` and language switching are used. [react-i18next 17 changelog](https://raw.githubusercontent.com/i18next/react-i18next/master/CHANGELOG.md) changes generated Trans keys with mixed HTML/interpolation; no Trans component use was found. Try the major pair with DE/EN hydration/interpolation coverage.
- [Lucide v1](https://lucide.dev/guide/version-1) removes brand icons/UMD and changes accessibility defaults. Current imports are general icons (lock, help, user, phone, chevrons), with no brand icons or UMD consumption identified. Visual/a11y smoke is needed; this is a bounded candidate rather than an automatic refactor.
- [RTK 2.13](https://github.com/reduxjs/redux-toolkit/releases/tag/v2.13.0) changes build tooling while retaining exports/API layout and fixes RTK Query behavior. Verify request deduplication, auth hydration and generated client. [Echo 2.5.0](https://github.com/laravel/echo/releases/tag/v2.5.0) specifically changes RN consumers to CJS resolution; `metro.config.js` currently forces an ESM bundle because older CJS failed. Inspect the new exports, test bundling on both platforms and realtime flows, then keep or remove the workaround based on evidence.
- Keep Testing Library 13.3.3 and Jest 29.7.0 with the selected jest-expo preset; no separate test-framework migration is planned.
- Keep ESLint in its compatible major, targeting 9.39.5. Finish the selected Paper/bottom-sheet/Redux/formatter patch-range review after resolution.

## Implementation checks and follow-ups

### Additional resolved-package review (2026-10-07)

Boost also advances its internal MCP dependency to 1.0.1 and Roster to 1.0.0. [Roster's upgrade guide](https://github.com/laravel/roster/blob/v1.0.0/UPGRADE.md) replaces the Roster scan API, package/IDE enums and version checks with the Project facade and ecosystem-specific queries; renamed package accessors and removed host-binary detections also affect extensions. No application or custom guideline references to those removed APIs were found. [MCP 1.0.1](https://github.com/laravel/mcp/releases/tag/v1.0.1) validates loopback redirect hosts. Larastan 3.12.3 requires PHPStan 2.3.0; its new inference is checked against the application rather than suppressed with additional ignores.

The PHPUnit/ParaTest patch pair also resolves [DeepCopy 1.14.0](https://github.com/myclabs/DeepCopy/releases/tag/1.14.0), which drops PHP 7 and changes object tracking to WeakMap; the existing PHP 8.4 runtime meets its floor. CPU-core-counter [1.4.0](https://github.com/theofidry/cpu-core-counter/releases/tag/1.4.0) and [1.4.1](https://github.com/theofidry/cpu-core-counter/releases/tag/1.4.1) change quota/affinity detection and Windows CPU discovery, which can change parallel worker counts. Symfony Console/Process remain on 7.4 patch releases.

The final mobile audit detected the newly published shell-quote advisory. A bounded 1.x override resolves 1.12.0; quoted arguments, round trips and malformed comment/newline input were checked using parser/quoting APIs. Three other advisories remain blocked by an incompatible decoder export or absence of a published fix; see the implementation record and follow-up draft in the plan.

Backend: run all commands through Sail; `composer validate`, Composer dry-run/conflict analysis, `composer audit`, `composer quality`, parallel tests for changed ParaTest. Confirm PostgreSQL behavior as well as SQLite tests. Diff OpenAPI and exercise admin auth/CRUD, provisioning, MQTT dedup/ack/reconnect, event replay and tracing.

Mobile: at each SDK checkpoint align with `pnpm exec expo install --fix`, run Expo Doctor, `pnpm check`, `pnpm test:ci`, and build a fresh native dev client. CI's Node22 selector should resolve to a sufficiently recent patch. Test iOS and Android: persisted auth/server/language startup, sign-in/reset-password links, terms HTML, compartment list/open progress/help phone link, BottomSheet keyboard/gestures, offline recovery, realtime subscription and account logout. SDK/Jest checks cannot replace native smoke. Preserve native runtime-version/build compatibility when issuing a native release.

Inspect broad existing overrides before changing them: mobile currently forces major jumps such as `js-yaml>=5.2.2`, `fast-uri>=4.1.2`, and `uuid>=11.1.1` for older transitive consumers. Record why each still exists, compare the resolved SDK graph, and remove only when the new graph fixes the original advisory and tests pass. Do not lower security floors to make the solver succeed.

Document actual blocked upgrades and create follow-ups only as required by the issue descriptions, naming the blocker, impact and proposed next step. The user approved iOS 16.4+ and SDK 57. No speculative TypeScript, testing-framework or Laravel-major migration is planned. Implementation and the blocked follow-up creation attempt are recorded in the companion plan.
