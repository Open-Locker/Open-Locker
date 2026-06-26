# Mobile App — Internal Test Builds & CI (Maintainer Guide)

> **Scope:** repeatable INTERNAL testing pipeline. Public App Store / Play Store
> release is explicitly OUT OF SCOPE (issue #19). Decision record: ADR-0028.

## Overview

On every push to `main` that touches `mobile-app/**`, GitHub Actions produces a
new **internal test build** for Android and iOS using `eas build --local` on
GitHub runners. Signing credentials live on EAS and are fetched at build time via
`EXPO_TOKEN` — **never in the repo**.

```
push to main ──▶ GitHub Actions (.github/workflows/mobile-app-build.yml)
                   ├─ ubuntu-latest : eas build --local --platform android
                   └─ macos-latest  : eas build --local --platform ios
                         └─ signing creds fetched from EAS via EXPO_TOKEN
```

## Project facts

| Thing | Value |
|---|---|
| EAS project | `@merona-apps/open-locker-mobile` |
| EAS project ID | `4fabf71b-3500-458d-b89c-59eca6c6ce82` (committed default in `app.config.ts`, not secret) |
| EAS account / owner | `merona-apps` |
| Bundle ID (iOS + Android) | `de.merona.openlocker` (set via `APP_ID_BASE`) |
| Apple Team | `UKC9C5ZQPC` (merona, Company/Organization) |
| Google Play account | merona |
| Build profile | `production` (so the same artifact can be promoted later) |

> ⚠️ `app.config.ts` **throws** for the `production` variant unless `APP_ID_BASE`
> (or `APP_ID_BASE_IOS`/`APP_ID_BASE_ANDROID`) is set. CI sets it inline.

## Credentials — where they live & who owns them

All signing material is stored on **EAS servers** (managed credentials). Nothing
signing-related is committed to the repo.

| Credential | Stored on | Owner / account | Notes |
|---|---|---|---|
| Android keystore (JKS) | EAS | merona-apps | alias `c8280910…`, created ~Mar 2026 |
| iOS distribution certificate | EAS (+ Apple portal) | Apple Team `UKC9C5ZQPC` | **expires 2026-07-13** |
| iOS provisioning profile | EAS (+ Apple portal) | Apple Team `UKC9C5ZQPC` | **expires 2026-07-13**, App Store type |
| App Store Connect API key | EAS | Apple Team `UKC9C5ZQPC` | role ADMIN |

> ⚠️ **iOS cert + profile expire 2026-07-13.** EAS auto-renews on the next build.
> If the pipeline hasn't built by then, the first build triggers renewal.

## How it's used in CI

The workflow only needs one secret:

| Secret | What | Source |
|---|---|---|
| `EXPO_TOKEN` | EAS auth token for CI | expo.dev → Account → Access Tokens (scope: merona-apps) |

`APP_ID_BASE=de.merona.openlocker` is a non-secret env var set inline in the
workflow. With `EXPO_TOKEN`, `eas build --local` fetches signing credentials from
EAS at build time (`credentialsSource: remote`) — no keystore/cert secrets needed.

### Fallback: injecting credentials as secrets
If remote fetch ever fails non-interactively, pull the credentials locally
(below) and add them as base64 secrets, reconstructing `credentials.json` in the
workflow before the build:

| Secret | From |
|---|---|
| `ANDROID_KEYSTORE_BASE64` | `base64 -i credentials/android/keystore.jks` |
| `ANDROID_KEYSTORE_PASSWORD` / `ANDROID_KEY_ALIAS` / `ANDROID_KEY_PASSWORD` | `credentials.json` |
| `IOS_DIST_CERT_BASE64` | `base64 -i credentials/ios/dist-cert.p12` |
| `IOS_DIST_CERT_PASSWORD` | `credentials.json` |
| `IOS_PROVISIONING_PROFILE_BASE64` | `base64 -i credentials/ios/profile.mobileprovision` |

## Using credentials locally

```bash
cd mobile-app
APP_ID_BASE=de.merona.openlocker eas credentials --platform ios     # → production → credentials.json → Download
APP_ID_BASE=de.merona.openlocker eas credentials --platform android # → production → credentials.json → Download
```
This writes (all **gitignored**, never commit): `credentials.json`,
`credentials/ios/dist-cert.p12`, `credentials/ios/profile.mobileprovision`,
`credentials/android/keystore.jks`.

## Distribution to testers

- **Android:** the `production` profile sets `android.buildType: "apk"`, so the
  build is an installable `.apk` uploaded as a GitHub Actions artifact. Testers
  download it from the workflow run and sideload it.
- **iOS:** the workflow runs `eas submit` to push the `.ipa` to **TestFlight**
  (App Store Connect beta — internal testing, not a public release). Testers
  accept a TestFlight invite. (Ad-hoc install would need device UDIDs registered
  — not set up.)

## Manual rebuild

From the GitHub Actions tab use **Run workflow** (`workflow_dispatch`), or locally:
```bash
cd mobile-app
APP_ID_BASE=de.merona.openlocker eas build --local --profile production --platform android
APP_ID_BASE=de.merona.openlocker eas build --local --profile production --platform ios
```
(Local builds need Java/Android SDK for Android and Xcode for iOS.)

## Credential rotation

- **Android keystore:** never rotate casually — re-signing breaks installs / Play identity.
- **iOS cert/profile:** EAS regenerates via `eas credentials`; if using the secret
  fallback, re-download and update the GitHub secrets.

## Open items (track before declaring done)

- [x] Android artifact: `.apk` for sideload internal (`android.buildType: "apk"` in `eas.json`).
- [x] iOS: TestFlight upload step added (`eas submit` in the workflow).
- [ ] Add `EXPO_TOKEN` secret to the GitHub repo.
- [ ] Validate via `workflow_dispatch`, then a real push to `main`.
- [ ] iOS first run: if `eas submit` asks for the app's numeric id, set
      `submit.production.ios.ascAppId` in `eas.json`.
- [ ] ≥1 teammate installs a CI-produced build on a device (issue #19 acceptance).
