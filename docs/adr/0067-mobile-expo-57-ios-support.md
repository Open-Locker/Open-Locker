# ADR-0067: Adopt Expo SDK 57 and iOS 16.4 support

## Status

Accepted

## Date

2026-10-06

## Context

Issue #304 requests an upgrade to the latest supported Expo SDK. SDK 56 raised
the minimum iOS version from 15.1 to 16.4, which SDK 57 retains. The app imports
Reanimated and Worklets; earlier SDK 56/57 releases have documented Hermes memory
and development startup regressions.

## Decision

Adopt Expo 57.0.26 with its aligned React Native 0.86.3 dependency set and support
iOS 16.4 and newer. The product owner explicitly approved this support change.
Use the SDK's native deployment defaults and require Xcode 26.4 or newer for iOS
builds. Migrate theme imports to Expo Router's navigation APIs. Retain TypeScript
5.9.3 using Expo's documented compiler opt-out.

## Alternatives Considered

- Stay on SDK 55 to retain iOS 15.1: rejected by the product owner's support decision.
- Ship SDK 56: unnecessary checkpoint with known Hermes regressions affecting this app.
- Upgrade TypeScript: not required by the issue or the SDK; retain the agreed compiler.

## Consequences

The app drops iOS 15 devices. Users on those devices cannot install this release.
Fresh native development/store builds are required; an OTA update cannot upgrade
the native runtime. Expo 57.0.26 includes the relevant Hermes fixes. JavaScript
checks and bundles cannot replace Android/iOS native build and device validation.

## References

- [Issue #304](https://github.com/Open-Locker/Open-Locker/issues/304)
- [SDK 56 support floor, Router migration and TypeScript opt-out](https://expo.dev/changelog/sdk-56)
- [SDK 57 React Native and Hermes fixes](https://expo.dev/changelog/sdk-57)
- [Dependency upgrade plan](../dependency-upgrade-plan.md)
