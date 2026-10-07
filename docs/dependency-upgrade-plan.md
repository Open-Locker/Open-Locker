# Dependency upgrade plan: issues 304, 305 and 306

Research date: 2026-10-06. Repository baseline: `751993d2c5fa9aeaa32fc64c30249791d79d2e1e`.

## Scope and approach

The three requests are [mobile #304](https://github.com/Open-Locker/Open-Locker/issues/304), [backend #305](https://github.com/Open-Locker/Open-Locker/issues/305), and [locker client #306](https://github.com/Open-Locker/Open-Locker/issues/306). Each permits small compatibility fixes and asks for substantial migrations to be deferred with a documented blocker and follow-up. Implementation results and remaining validation are recorded below.

Scope is limited to compatible dependency updates, the Expo SDK upgrade, required compatibility fixes, lockfiles and the issue-specific checks. Keep TypeScript 5.9.3 in both TypeScript modules. Retain existing runtime/package-manager versions unless an in-scope dependency requires a change. Major migrations that are not needed for these updates are not planned work.

The user requested sequential dependency commits and separate compatibility-fix commits on the current worktree. Work is on `codex/dependency-upgrades` (the supplied worktree initially had a detached HEAD). Client/mobile work proceeds independently while the Sail environment is unavailable. Each release must preserve the shared MQTT schemas. Website, hardware, and `utils/modbus-cli` are outside these issues.

Read lockfiles rather than treating manifest minimums as installed versions. For example, the client already has `modbus-serial` 8.0.25 and Winston 3.19.0. The backend already has Filament 5.7.8 and Livewire 4.4.3; references to Livewire 3 in agent guidance are stale.

For each module:

1. Capture baseline checks, audit output, direct/transitive outdated lists and runtime versions. Investigate pre-existing failures separately.
2. Update packages in coherent groups; inspect the complete release interval between the locked and resolved versions, including security fixes in minor/patch releases. Recheck registry versions at implementation time.
3. Regenerate that module's lockfile with its declared package manager. Preserve peer constraints and audit every security override against its actual parent range; broad overrides can silently cross major versions.
4. Run the checks and smoke tests below. Record exact resolved versions, intentional holds, regressions fixed, and follow-up descriptions. Do not describe a package as compatible merely because its version is a patch/minor.
5. Document major version changes, checks performed, intentionally held packages and actual blockers. Create follow-up issues only when an in-scope upgrade encounters a substantial blocker, as requested by the issue descriptions.

## Locker client: #306

### Proposed targets

Versions below were checked against [npm registry metadata](https://registry.npmjs.org/). Targets are candidates pending install and runtime validation.

| Dependency | Locked version | Proposed action |
| --- | --- | --- |
| `@opentelemetry/api` | 1.9.1 | Keep 1.9.1 |
| `@opentelemetry/core`, `resources`, `sdk-trace-base`, `sdk-trace-node` | 2.10.0 | Upgrade together to 2.11.0 |
| `@opentelemetry/api-logs`, `sdk-logs`, both OTLP HTTP exporters | 0.221.0 | Explicitly advance all to 0.222.0; `^0.221.0` excludes 0.222 |
| `@opentelemetry/semantic-conventions` | 1.43.0 | Keep 1.43.0 |
| `mqtt` | 5.15.1 | Upgrade to 5.16.0 first |
| `dotenv` | 16.6.1 | Candidate 18.0.5, with startup/env regression checks |
| `js-yaml` | 4.3.2 | Keep 4.3.2 to preserve deployed configuration semantics |
| `@types/js-yaml` | 4.0.9 | Keep with YAML 4; remove if moving to YAML 5, which ships declarations |
| `p-queue` | 8.1.1 | Candidate 9.3.3 after queue behavior and compiled startup checks |
| `serialport` | 13.0.0 | Keep 13.0.0; retain native-binding checks |
| `modbus-serial` | 8.0.25 | Keep 8.0.25; already current |
| `winston`, `winston-transport` | 3.19.0 / 4.9.0 | Keep; already current |
| `zod` | 4.4.3 | Upgrade to 4.6.5 with payload acceptance/rejection parity |
| `ajv`, `ajv-formats` | 8.20.0 / 3.0.1 | Keep; already current |
| `oxlint` | 1.70.0 | Candidate 1.87.0; review newly reported diagnostics |
| `oxfmt` | 0.55.0 | Candidate 0.72.0, in a distinct formatting commit |
| `fallow` | 2.96.0 | Candidate 3.31.0; verify audit and boundary commands/config |
| `typescript`, `ts-node` | 5.9.3 / 10.9.2 | Keep; no compiler or execution-tool migration |
| `@types/node` | 24.13.2 | Keep unless an in-scope update requires a compatibility fix |
| Node runtime | Docker and CI: 22 | Retain Node 22; ensure patch meets updated dependencies' minimum |
| pnpm | 9.0.0 | Retain 9.0.0 and regenerate the existing lockfile |

### Release notes and concrete compatibility risks

- **MQTT 5.16.0:** [release notes](https://github.com/mqttjs/MQTT.js/releases/tag/v5.16.0) describe broker-packet security fixes, including malformed SUBACK/CONNACK handling. Several apply to MQTT 5; the adapter does not itself set `protocolVersion`, so do not change protocol versions as part of this work. Also include the 5.15.2 TLS SNI fix in the review. Exercise persistent sessions, reconnect/resubscribe, retained states, QoS 1 and credential revocation against Mosquitto.
- **OpenTelemetry 2.11 / 0.222:** [stable notes](https://github.com/open-telemetry/opentelemetry-js/blob/main/CHANGELOG.md) and [experimental notes](https://github.com/open-telemetry/opentelemetry-js/blob/main/experimental/CHANGELOG.md). Experimental fail-fast changes affect declarative `sdk-node` setup; this client constructs `NodeTracerProvider` and logging providers directly. Check context propagation, export failures, log redaction, and flush/shutdown. Do not accidentally select development 3.x/0.300 packages: their provider and Logs API migrations are separate work.
- **dotenv 16 to 18:** [changelog](https://github.com/motdotla/dotenv/blob/v18.0.5/CHANGELOG.md) changes logging and removes vault/preload functionality. Importantly, [tagged config source](https://github.com/motdotla/dotenv/blob/v18.0.5/config.js) and [package exports](https://github.com/motdotla/dotenv/blob/v18.0.5/package.json) still support `dotenv/config`, used by both entry points. Do not replace it solely on the basis of the preload note. Verify the published package import, env-loading order, existing process-variable precedence, comments/quoted values and quiet logging; this is a small upgrade if these pass.
- **p-queue 8 to 9:** [9.0 notes](https://github.com/sindresorhus/p-queue/releases/tag/v9.0.0) require Node 20+, remove `throwOnTimeout` and make queue timeouts reject. The client uses concurrency-one queues with priorities, without queue timeout options or a custom queue class. Both 8 and 9 are ESM: ESM is not a new v9 break. Nevertheless run the compiled CommonJS service on the actual Node patch, because `tsc` success alone does not prove imports execute. Check command and hardware ordering, rejection propagation, reconnect retry and draining during shutdown.
- **js-yaml hold:** the [4-to-5 migration guide](https://github.com/nodeca/js-yaml/blob/master/docs/migrate_v4_to_v5.md) changes the default schema, merge-key/tag behavior, empty-input errors, complex keys and exports. Keep compatible 4.3.2 and record this reason; no deployed-config parser migration is scheduled.
- **TypeScript hold:** keep 5.9.3. [TypeScript 7](https://devblogs.microsoft.com/typescript/announcing-typescript-7-0/) has no compiler API, which affects ts-node; no compiler upgrade is required for the selected dependencies.
- **Formatting/lint/audit:** [oxfmt 0.72](https://github.com/oxc-project/oxc/releases/tag/oxfmt_v0.72.0) switches Markdown formatting with an explicit breaking note; [oxlint 1.87](https://github.com/oxc-project/oxc/releases/tag/oxlint_v1.87.0) changes diagnostics/fixes. Check all intervening tags before installing. [Fallow 3.0](https://github.com/fallow-rs/fallow/releases/tag/v3.0.0) explicitly preserves CLI/config contracts, but [3.31 and preceding upgrade notes](https://github.com/fallow-rs/fallow/releases) describe analysis/output/CI changes. Compare current `.fallowrc.json` and `fallow:boundaries` behavior rather than mass-editing application code to satisfy new findings.
- **Unchanged native packages:** [serialport releases](https://github.com/serialport/node-serialport/releases) and [modbus-serial 8.0.25](https://github.com/yaacov/node-modbus-serial/releases/tag/v8.0.25). No direct version upgrade is needed today; a Node/pnpm change can still change native installation behavior. The unusual manifest minimum `8.0.23-no-serial-port` already resolves to regular 8.0.25; inspect the dependency tree before changing it.
- **Zod:** [4.6 release](https://zod.dev/blog/zod-4-6) and [4.6.5 notes](https://github.com/colinhacks/zod/releases/tag/v4.6.5). Treat stricter validation and altered error messages as observable MQTT behavior; existing schema and error-mapping tests are the gate.

The full [oxfmt changelog](https://github.com/oxc-project/oxc/blob/main/apps/oxfmt/CHANGELOG.md) also records native YAML/CSS/GraphQL formatting and config-discovery changes in the upgrade interval. This module's formatter scans more than TypeScript, so review configuration and Markdown/YAML churn. The [oxlint changelog](https://github.com/oxc-project/oxc/blob/main/apps/oxlint/CHANGELOG.md) includes a React compiler rule split, but the current client enables only TypeScript, Unicorn and Oxc plugins; that React change does not directly apply. The newer tools require Node 22.12+ when using the Node 22 line.

### Implementation and validation

1. Update MQTT and the coordinated telemetry set, then Zod. Run `pnpm check`, `pnpm build`, `pnpm test`.
2. Update dotenv and p-queue individually, applying straightforward compatibility fixes; verify `pnpm dev`, `pnpm sim` and emitted production entry points with temporary non-production configuration. Retain their compatible versions if a substantial blocker appears.
3. Update lint/format/audit tools separately. Run `pnpm fallow` and `pnpm fallow:boundaries`; review formatting-only changes independently.
4. Build the client Docker image for both `linux/amd64` and `linux/arm64`, including frozen installation and real loading of serialport bindings. CI's ordinary image checks currently build amd64; the release workflow also builds arm64. A successful amd64 build is insufficient evidence for Pi support.
5. Use the existing MQTT transport, broker-revocation, contract, shutdown, queue-priority, Waveshare, RS485 transport, YAML config, simulator and telemetry tests. Add targeted regression tests only for changed behaviors not already exercised. Native/hardware execution remains a release validation step, not something these mocks prove.

## Backend: #305

Detailed package inventory, upstream references and migration applicability are in [the release research](dependency-upgrade-release-research.md). Use the actual Composer lock as the baseline, including Filament 5 / Livewire 4.

### Upgrade sequence

1. **Compatible baseline:** advance Laravel 12.62.0 to 12.69.3 and other packages within their current supported lines, including Filament 5.10.0, Livewire 4.4.7 and event-sourcing 7.15.1. Keep the test tools coupled: PHPUnit 11.5.57 with ParaTest 7.8.6 for this first checkpoint. Use a bounded ParaTest constraint, such as `~7.8.6`, rather than widening PHPUnit just to let Composer select the newest ParaTest. Pint 1.32.1 requires PHP 8.3 and fits the existing deployed PHP 8.4 runtime. The original lock already includes Symfony 8 packages requiring PHP 8.4; validate this lock on 8.4 and do not introduce a runtime upgrade.
2. Update `composer.lock` and apply straightforward fixes needed by those compatible versions. Keep Laravel 12, Tinker 2 and PHPUnit 11; no framework/test-runner major migration is scheduled.

### Breaking changes to address

Review release notes, deprecations and security advisories for the selected Laravel 12 and Composer updates. Check worker exit handling, Filament actions, Scramble schema inference, event-sourcing compatibility, telemetry and Reverb behavior. Preserve application behavior and API contracts. Laravel 13's CSRF/session migration is not part of this plan.

ParaTest illustrates a same-major compatibility trap: latest 7.26.0 requires PHPUnit 13. Keep ParaTest 7.8.6 paired with PHPUnit 11.5.57 rather than expanding the scope to a test-runner major migration. The detailed research links the requirements.

### Validation

Run backend commands through Sail from `locker-backend/`:

```sh
vendor/bin/sail composer outdated --direct
vendor/bin/sail composer audit
vendor/bin/sail composer update --with-all-dependencies --dry-run
# After each selected, bounded update:
vendor/bin/sail composer validate --strict
vendor/bin/sail composer check-platform-reqs
vendor/bin/sail composer quality
vendor/bin/sail composer test:parallel
```

Exercise token auth/verification, role access, Filament resource actions, event projection/replay, queued reactors, MQTT authentication and command deduplication, Reverb private-channel authorization, telemetry and health commands. Include PostgreSQL-backed checks, since CI's SQLite tests cannot prove production database behavior.

Compare the live Scramble `/docs/api.json` before/after for semantic API differences, not ordering/formatting alone. With the upgraded backend running and `EXPO_PUBLIC_API_BASE_URL` pointing to it, run mobile `pnpm generate:api` and review generated-client changes, then mobile typecheck/tests. Upgrade-induced contract changes require separate work rather than silently regenerating them away.

Smoke-test worker/MQTT/Reverb processes where affected by the selected updates. Keep the deployed PHP 8.4 runtime.

## Mobile: #304

The latest stable target checked for this plan is **Expo 57.0.27**. The goal of #304 remains the latest project-supported SDK. Expo 55.0.31 is a compatibility checkpoint and a fallback only when a documented blocker prevents proceeding. Exact SDK native-package sets and all other direct dependencies are in [the release research](dependency-upgrade-release-research.md).

### Upgrade sequence and decision points

1. **54 to 55 checkpoint:** use Expo's dependency alignment to upgrade React, React Native, Expo modules, Router, animation/native libraries, types and the Jest/ESLint configuration together. Prove the SDK 55 build before moving on.
2. **Audit 56 migrations, then target 57:** [SDK 56 notes](https://expo.dev/changelog/sdk-56) and [SDK 57 notes](https://expo.dev/changelog/sdk-57) both matter. Do not ship an intermediate SDK 56 build: its documented Hermes regression affects applications importing Worklets/Reanimated, and this application uses both. SDK 57 with React Native 0.86.3 contains the fix.
3. **Device-support decision:** the user approved iOS 16.4+ and SDK 57. See [ADR-0067](adr/0067-mobile-expo-57-ios-support.md). SDK 55 is a JavaScript-validated checkpoint, not the final SDK target.
4. **Navigation compatibility:** SDK 56 changes Router's React Navigation integration. Actual application references are `ThemeProvider` in `app/_layout.tsx` and `DarkTheme`/`DefaultTheme`/`Theme` in `src/theme/themeFactory.ts`. Migrate those imports/types to the selected Router APIs, and confirm Paper's merged theme remains correct. Evaluate bottom-sheet/native peers against the SDK's exact Reanimated/Worklets versions.
5. **Other packages:** update i18next/react-i18next, Lucide, Redux Toolkit, Echo/Pusher and UI dependencies to compatible versions, applying only straightforward fixes. Keep TypeScript 5.9.3 and compatible Jest/ESLint/testing-library families. Review existing pnpm overrides only as needed to resolve and validate the updated SDK graph.

### Validation

Use pnpm in `mobile-app/` and choose the SDK version explicitly instead of a moving `latest` tag:

```sh
pnpm exec expo install expo@~55.0.31 --fix
pnpm exec expo install --check
pnpm check
pnpm test:ci
# Repeat alignment and validation for ~57.0.27 after the support decision.
```

Run a clean native build of both Android and iOS using the existing development/preview profiles; `expo-doctor`, JavaScript tests and Expo Go alone do not prove native compatibility. Confirm EAS/Xcode/Node image requirements for the SDK. Review generated native diffs before accepting regeneration; SDK 57 changes the default behavior of `expo prebuild` to clean native directories.

Smoke-test cold/warm startup, fonts/icons and splash, login/logout and SecureStore persistence, email/password-reset deep links, terms HTML, EN/DE translations, dark/light themes, bottom sheets and gesture/back behavior, locker unlock and error feedback, realtime reconnection and foreground/background transitions. Compare API codegen against the backend as described above.

## Completion and blocked updates

For each issue, document selected versions, major version changes, checks actually performed and intentionally held dependencies. If an in-scope upgrade requires a substantial refactor, product/architecture decision, protocol change or hardware validation beyond the issue, retain the latest compatible version and create the follow-up requested by that issue, including blocker, impact and proposed next step. Do not create speculative compiler, package-manager or framework modernization tasks.

## Implementation record (2026-10-07)

Updates are committed sequentially on `codex/dependency-upgrades`; compatibility fixes and generated assets have separate commits. No changes were pushed. Exact versions are recorded in the three lockfiles. TypeScript remains 5.9.3 in both TypeScript modules, pnpm remains 9.0.0, and deployed Node/PHP runtime lines remain unchanged.

| Module | Implemented updates |
| --- | --- |
| Client #306 | MQTT 5.16.0; OpenTelemetry stable 2.11.0 / experimental 0.222.0; Zod 4.6.5; dotenv 18.0.5; p-queue 9.3.3; oxlint 1.87.0; oxfmt 0.72.0; Fallow 3.31.0; bounded fast-uri/ip-address security patches. |
| Backend #305 | Laravel 12.69.3; Filament 5.10.0 / Livewire 4.4.7; Scramble 0.13.47; OpenTelemetry 2.2.6; Reverb 1.12.0; Sanctum 4.3.3; MQTT client 1.8.0; event-sourcing 7.15.1; Larastan 3.12.3 / PHPStan 2.3.0; Boost 2.10.2; Pail 1.2.7; Pint 1.32.1; Sail 1.68.0; Mockery 1.6.15; Collision 8.9.5; PHPUnit 11.5.57 / ParaTest 7.8.6. Laravel-lang's unchanged 6.8.0 is bounded to fix strict manifest validation. |
| Mobile #304 | Expo 57.0.27 and aligned native modules; React 19.2.3 / RN 0.86.3; Router 57.0.25; Reanimated 4.5.1 / Worklets 0.10.1; bottom-sheet 5.2.14; Redux Toolkit 2.13.0 / React Redux 9.3.0; i18next 26.4.2 / react-i18next 17.0.15; Echo 2.5.0 / Pusher 8.6.0; Paper 5.15.3; Lucide 1.52.0; Expo icons 15.1.1; ESLint 9.39.5; Prettier 3.9.9 / plugin 5.5.6; compatible transitive security fixes including shell-quote 1.12.0. |

### Compatibility fixes and intentional holds

Mobile migrates theme imports to `expo-router/react-navigation`, handles the native color-scheme `unspecified` value, removes obsolete architecture/edge-to-edge configuration and supplies the required native plugins/Jest peer. The iOS 16.4+ decision is recorded in ADR-0067. Three newly enabled React Compiler ESLint rules remain warnings for existing patterns (26 findings); other lint gates remain active. Echo's Metro ESM redirect remains because its selected package still exposes the problematic CJS entry point.

Scramble's updated PHPDoc inference exposed a mismatch between the nullable `LockerBank.location_description` database/API field and its non-null PHPDoc. Corrected the PHPDoc with a response/schema regression test. Regenerated the mobile API client against a running backend with a migrated SQLite database; the generated timestamp becomes optional/nullable. No API runtime behavior was deliberately changed. Filament's published CSS/JS/fonts were regenerated in their own commit.

Client YAML 4 is retained to preserve deployed configuration semantics. PHPUnit 11 is retained, and ParaTest is bounded to `~7.8.6` because newer 7.x releases require PHPUnit 13. Jest 29 and Testing Library 13 remain aligned with Expo's preset. Unchanged packages already current in their supported families were left alone. Website, hardware and Modbus utilities were not upgraded.

### Checks and limits

- Client: frozen install, type/lint/format checks and build pass; audit clean. Full tests: 349 pass, 12 skipped, three failures identical to the pre-upgrade Windows baseline (credential-file mode, cache path separators and directory-close mocking). Focused queue, telemetry and payload validation tests pass, as do dotenv precedence and Node 22 compiled startup/native binding load checks. Fallow reports three boundary violations also reproduced with its previous version.
- Mobile: frozen install and Expo alignment pass; 87 tests in 17 suites pass, including after API regeneration. Typecheck, lint and format pass. Expo Doctor reports only the existing unmaintained `react-native-render-html` warning (20/21 checks pass); it was not hidden. Android release x86_64 builds succeed on SDK 57.0.26 and 57.0.27. The latter APK installs and cold-starts to the sign-in screen with fonts/icons and no AndroidRuntime/ReactNativeJS errors. Emulator System UI became unresponsive, limiting interaction smoke; this is not full device-flow validation. iOS JavaScript export succeeds, but native iOS/Xcode checks remain outstanding.
- Backend: Docker/Sail was unavailable; the user approved native PHP/Composer fallback. Final Composer install with discovery/Filament scripts, strict validation, platform requirements and audit pass; audit has no advisories or abandoned packages. Pint and PHPStan pass. Full PHPUnit suite: 467 tests, 1,677 assertions, 461 passing, two skips and four publisher schema-loading errors, so `composer quality` exits 2. Those four errors also reproduce with old Mockery 1.6.12; the unchanged helper builds an invalid file URI from a Windows path. Focused token auth, private-channel auth, Filament access/actions, MQTT health, event-sourcing atomicity and trace propagation tests pass. Mockery's command-response tests and the two-process PHPUnit/ParaTest unit suite pass (10 tests / 94 assertions). Roster scanning and Boost's local command registration/help pass. Final live API regeneration and mobile typecheck pass without additional changes.
- Remaining environment checks: Sail/container rebuild, PostgreSQL, Docker client builds for both architectures, broker/hardware integration, native iOS with Xcode 26.4+, and real-device authentication/deep links/gestures/reconnection flows. These checks are not represented as passing.

The full `composer test:parallel -- --processes=2` run also completes all 467 tests with 1,677 assertions, the same four schema-loading errors and two skips (exit 2). No additional parallel-test failures were observed. Temporary backend/API servers and the Android emulator were stopped after validation.

### Blocked mobile security follow-up draft

Issue creation through the GitHub integration returned HTTP 403 (`Resource not accessible by integration`); no follow-up issue was created. The reviewable draft is retained here:

**Title:** Resolve remaining Expo/Jest transitive security advisories after SDK 57 upgrade

**Context:** Follow-up to #304. The final audit still reports:

| Dependency | Advisory / impact path | Blocker |
| --- | --- | --- |
| decode-uri-component 0.2.2 | [GHSA-vcc3-ghjq-m6fr](https://github.com/advisories/GHSA-vcc3-ghjq-m6fr), Router's query-string parser | Fixed 0.5 exports ESM; overriding it breaks the CJS consumer with `decodeComponent is not a function`, including normal deep links. The incompatible override was reverted. |
| node-forge 1.4.0 | [GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv), Expo CLI signing dependency | No fixed version published at the audit date. |
| braces 3.0.3 | [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm), Jest graph | No fixed version published at the audit date. The newest Metro file-map removes its separate dependency. |

**Proposed next step:** Upgrade the parent parser/build/test packages when compatible fixes are published, or plan a bounded parent migration. Assess reachability separately for the runtime deep-link parser and development/build dependencies. Repeat audit, malformed/Unicode/normal deep-link parity, JavaScript tests and platform exports; do not force an incompatible decoder major solely to clear the audit.
