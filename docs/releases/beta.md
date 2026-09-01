# Beta Release — Feature and Change List

**Status:** source tags cut; artifact publication and field acceptance incomplete
**Date:** 2026-08-25
**Issue:** #209

## Beta goal

Open Locker has no production deployment. This Beta is a **controlled
pre-production pilot**, not a production launch or an unrestricted public
rollout. Execution is governed by the
[Beta release checklist](../release-checklist.md).

The goal of this Beta is to exercise the **core loop** end-to-end — authenticate, see
accessible compartments, open one, and watch door state update live — and to establish a
**baseline for collecting user feedback**. Feature completeness beyond that loop is
secondary; field hardening and release mechanics are what make the exercise trustworthy.

## Highlights (TL;DR)

- First component-specific Beta source tags created from the same `main` commit.
- Core loop: sign in → list accessible compartments → open → live door state + content notes.
- Event-sourced compartment domain with auditable content notes (Item domain removed).
- Capability-based roles (incl. manager), group access, terms gate, EN/DE in app and admin.
- Canonical MQTT contract; locker-client v2 on Pi with Modbus, recovery, and fleet simulator.
- Deployment-neutral production MQTTS with private broker networking and verified
  client TLS.
- Mobile app on Expo with RTK Query codegen and Reverb realtime.
- Filament v5 ops-oriented admin: users, groups, compartments, open requests, audit log.
- Event-sourced writes are atomic; MQTT identities and locker-client recovery are
  hardened.
- Release mechanics (#50) and this scope review (#209) are closed. Deployment
  and field acceptance remain Beta 2 work.

## Scope

This Beta is the first coordinated component-tagged release. This document has
two parts:

1. **Feature list** — what the system does at Beta, by component. This is the
   "what do we have" answer.
2. **Change log** — what landed, grouped by component and derived from the Conventional
   Commit history (path-filtered per component) plus the issues and pull requests.

Per the monorepo release strategy (`#50`; ADR-0043), the durable form of this
should be generated per component into the GitHub Release attached to each tag
(`backend-vX.Y.Z`, `client-vX.Y.Z`, `mobile-vX.Y.Z`). This file is the one-off
Beta list; it is not a committed rolling `CHANGELOG.md`.

**Verification basis:** a feature counts as done only when its implementation
is merged into `dev` and has passed the relevant checks. The three Beta tags
point to `main` commit `0365b8a5ff17e4e68f54293a4b939ba80e5843a4`.
Tag creation alone does not prove that every artifact was published or accepted
by its distribution channel; the outcome is recorded in section 3.

The baseline is the end of *Milestone 3 – MVP*. Everything below the "Earlier (MVP and
before)" headings predates that and is listed only for completeness of the feature set.

---

## Breaking changes

Call-outs for anyone upgrading from the pre-Beta / MVP tree. These are already reflected
in the feature list and change log; collected here so they are hard to miss.

- **Item domain removed** — compartments use event-sourced **content notes** instead of
  the old Item model/API. Clients and integrations must use the content-note flow.
- **locker-client v1 → v2** — wholesale hexagonal rewrite (`#175`). Deploy the v2 image
  and config; do not expect v1 runtime behaviour or layout.
- **Admin dashboard and navigation** — Filament dashboard dropped; landing is the
  Compartments list. Nav is operations-oriented (setup vs operations; Users/Groups under
  Access management) (`#48`, `#126`).
- **Committed `api.json` removed** — OpenAPI is served live via Scramble at
  `/docs/api.json`; regenerate mobile clients from the live URL (`#84`).
- **Mobile legacy API layer removed** — hand-written auth/API context replaced by the
  generated RTK Query client (`#85`).
- **Modbus no longer in the backend** — hardware I/O lives only in locker-client; the
  backend drives lockers over MQTT.
- **Group deletion → archiving** — hard delete replaced by archive + hardened archived
  behaviour (`#105`, `#106`, `#149`).

---

## 1. Feature list at Beta

### Backend — Laravel 12 API + Filament v5 admin

**Domain and data**
- Event-sourced compartment domain (aggregates, storable events, projectors, reactors).
- Atomic event-sourced write paths, so stored events and synchronous projections commit
  or roll back together (`#139`, PR `#231`).
- Compartment content notes — user-set, auditable, event-sourced (`#49`).
- Item domain dropped in favour of the content-note model.
- General audit log over the event store, browsable in the admin panel (`#109`).
- Crash-safe command-response dedup (`#188`).
- Compartment open requests recorded and queryable: an admin list, a per-locker-bank
  relation manager, and an open-status endpoint clients can poll by command id
  (the current mobile app does not use it).
- Group archiving instead of hard deletion, with archived-group behaviour hardened
  (`#105`, `#106`, `#149`).

**Access control**
- Capability-based roles and permissions, including a dedicated **manager** role, with
  manager boundaries enforced server-side (`#95`, ADR-0022).
- Group-based compartment access, effective access exposed via the API (`#46`, ADR-0021).
- User management in the Filament admin panel (`#18`).
- Role-permission management screens in the admin panel.
- Default role bindings seeded only on fresh installs.
- First admin bootstrapped from configuration (`#141`, ADR-0044).
- Last-admin role changes made concurrency-safe (`#187`).

**Auth**
- Password reset consolidated into a public web auth flow, incl. admin-triggered reset
  mail from the Filament user UI (`#54`, `#63`, `#66`).
- Public email-verification fallback; email verification enforced for locker actions.
- Versioned terms workflow with an acceptance gate; terms document versions are managed
  in the admin panel and served to the app.
- Self-service profile and password editing, for both the app and the admin panel.
- First and last name captured for users (`#55`, ADR-0020).

**Admin panel (Filament v5)**
- Single panel with session-based locale switching, EN/DE throughout, incl. auth pages
  and door-state labels, with the nav locale switcher fixed (`#154`, `#155`, ADR-0034).
- Operations-oriented navigation: setup vs operations split, Users and Groups under an
  Access-management section, dashboard dropped in favour of the Compartments list
  (`#48`, `#126`).
- Operational compartment view with door state, direct-user counts and content notes
  (`#96`, `#153`).
- Admins can edit and clear compartment content notes (`#136`).
- Compartment list made easier to navigate, with status badges repaired, the two
  compartment views aligned, and same-named locker banks kept in distinct groups
  (`#167`, `#190`, PR `#234`).
- Revoked and expired users hidden from the group member list (`#148`).
- Refreshed add-users-to-group UI, including bulk adding (`#146`, `#147`).
- Shared grant-access form across the User and Group relation managers; existing members
  excluded from the Add-member picker; only active direct grants shown by default
  (`#117`, `#123`, `#127`, `#144`).
- Role badges in user table and detail views; consolidated, localized role-management
  action (`#142`, `#143`).
- Provisioning details on the LockerBank resource: token shown on the form and in the
  table, and resettable from the panel (`#110`).
- Reduced and de-duplicated notifications (`#165`).
- Application version exposed in the API and shown in the sidebar footer (`#33`).
- Synchronous projectors with queued reactors, so admin edits read back immediately
  (`#128`, ADR-0033 synchronous projectors).

**Integration**
- Canonical MQTT contract: message taxonomy and wire contract, AsyncAPI spec and shared
  JSON Schemas, publishers and inbound handlers aligned, contract tests in both backend
  and client, contract validation in CI (`#72`–`#79`, ADR-0015, ADR-0018).
- `message_id` for technical dedup and `transaction_id` for command correlation, with a
  complete dedup policy (`#39`, `#41`, ADR-0002).
- Retained compartment state snapshots; command responses aligned to the contract.
- Connection status derived from heartbeat telemetry, with a scheduled offline-locker
  detector; liveness healthcheck for the MQTT listener (`#52`, ADR-0028).
- `identify` endpoint so the app can confirm which backend instance it is talking to
  before login (`#12`).
- Mosquitto authenticates against the API via `mosquitto-go-auth` (`/api/mosq/*`).
- Per-provisioning MQTT credential identities preserve locker identity separately,
  support revocation, and keep legacy credentials valid during the rollout
  (`#161`, PR `#227`, ADR-0050).
- Deployment-neutral production MQTT transport terminates trusted TLS at
  Traefik on public port 8883 while Mosquitto remains private on port 1883;
  production clients require `mqtts://` with certificate and hostname
  verification
  (`#163`, PR `#232`, ADR-0053).
- Request-scoped API localization via `Accept-Language` (ADR-0027).
- Live OpenAPI spec via Scramble at `/docs/api.json`; `api.json` no longer tracked
  (`#84`, ADR-0019).
- Realtime broadcasting to mobile over Reverb (door state, content notes)
  (`#45`, ADR-0023, ADR-0029), with door state verified end to end from MQTT snapshots
  and its projection updates fixed (`#44`, `#164`).
- End-to-end tracing and logging of the open flow with OpenTelemetry
  (`#65`, PR `#189`; ADR-0042).
- Command acknowledgement separated from door-open detection and alerting
  (`#94`; ADR-0040).

### Mobile app — React Native / Expo

- RTK Query client generated from the live backend OpenAPI spec.
- Compartment list and grid with open modal, live door state over Reverb, content notes.
- Sign-in, password reset and profile management; pre-login backend server switch.
- Terms modal flow with acceptance gate and bottom notices.
- Auto-logout on `401` with a session-expired message (`#60`).
- EN/DE localization with a persisted in-app language switcher; centralized theme and UI
  defaults, dark-mode-aware logo and splash scaling (`#93`, `#98`).
- Branch CI builds Android preview and unsigned iOS Simulator artifacts; signed
  TestFlight distribution is reserved for `mobile-v*` after Beta 2 issue #242
  (`#19`, ADR-0032, ADR-0055).
- Legacy auth context and hand-written API layer removed (`#85`).
- react-doctor evaluated for code health, expo-doctor kept in the quality gate (`#64`).

### Locker client — Raspberry Pi / Node + Docker

- **v2 hexagonal rewrite** replacing v1 (`#175`, ADR-0024).
- MQTT provisioning with persisted, zod-validated credentials and client-ID persistence.
- Command handling: `open_compartment`, `apply_config`, response ACKs, transaction-bound
  dedup, recoverable command responses (`#38`, `#40`, `#42`, `#171`).
- Modbus adapters for Waveshare relay boards: pulse/flash relay control, all relays off
  at startup, serialized operations, enforced RTU inter-frame delay, tolerance for
  unreachable boards (`#61`, ADR-0006, ADR-0007, ADR-0035).
- Door-sensor polling batched at 500 ms, publishing change-only snapshots
  (`#166`, ADR-0038).
- Runtime configuration applied from the server, with overlay validation.
- Runtime configuration uses live slave IDs and rejects incomplete mappings (`#174`).
- Heartbeat service for connection monitoring, plus an MQTT last-will message so the
  backend sees an ungraceful disconnect.
- Safe shutdown and async lifecycle handling (`#170`, ADR-0049).
- Inbound protocol guard and a dedup store in front of command handling.
- Modbus reconnect coordinator that marks a prolonged outage unreachable and keeps
  retrying until the bus recovers (`#172`, PR `#228`, ADR-0051).
- Hardened local persistence and credential handling (`#168`).
- Improved protocol errors and observability (`#173`), with verbose runtime logging
  (`#113`).
- Contract-aligned locker fleet simulator: in-memory bus, credential and overlay stores,
  scenario files and a traffic log, so the whole stack runs without hardware
  (`#82`, `#23`, ADR-0031, `docs/simulator.md`).
- Watchtower-based update flow; the Beta pins a verified immutable client image tag
  rather than `latest`.

### Hardware

- Test-locker Bill of Materials, EN and DE (`#47`).
- Circuit diagram and hardware plan (`#16`, `#26`).
- Flyback-diode protection for lock coils, integrated into the PCB (`#69`, `#80`).
- PCB mounting adapter model.

### Website and documentation

- Landing page moved into the monorepo, deployed to GitHub Pages at open-locker.org
  (`#53`, ADR-0036).
- English version of the site, URL-path-based localization
  (`#90`, ADR-0037, ADR-0039).
- Starlight documentation section with aligned branding; BOM migrated in as a Hardware
  page (`#92`); copy and layout fine-tuning (`#179`).
- Architecture docs, MQTT architecture and integration plan, AsyncAPI specs,
  observability notes, installation and simulator guides, and ADRs.

### CI / infrastructure

- Per-component path-filtered workflows for backend, mobile app and locker client.
- Docker images for backend and client published to GHCR.
- Quality gates: Pint, PHPStan (level 8), Jest, `node --test`, expo-doctor (`#199`).
- Dependency-audit gates, with security advisories patched as they surfaced.
- GitHub Actions updated to Node 24-compatible action releases (`#67`).
- Build-time work: isolated Docker caches, parallel Pint, superseded-run cancellation,
  single-platform client PR builds, Android internal build caching (`#204`; ADR-0047).
- Per-component release strategy decided: independent tag namespaces, standard
  per-component SemVer, generated release notes (`#50`; ADR-0043).
- Laravel Boost wired up for agent tooling (`#88`).

---

## 2. Change log since the MVP milestone

Grouped by component; `feat` / `fix` / `perf` from the Conventional Commit history on
`dev`, path-filtered. Dependency-audit bumps are collapsed into one line per component.

### Backend

**Features**
- Archive groups instead of hard delete (`#106`, `#149`)
- Improve and reduce Filament notifications (`#165`)
- Consolidate role management into a single localized action (`#142`)
- Show user roles as localized badges in table and detail views (`#143`)
- Show only active direct access grants in the compartment users table (`#144`)
- Run projectors synchronously for read-your-writes (`#128`)
- Group Users and Groups under an Access-management nav section (`#126`)
- Show user group memberships on the User edit page (`#122`)
- General audit log over the event store (`#109`)
- Role-permission management screens and capability-based roles with a manager role (`#95`)
- Operations-oriented Filament nav and compartment access screen (`#48`)
- Localize the Filament admin panel (EN/DE), incl. door-state labels and auth pages
- Request-scoped API localization via `Accept-Language` (ADR-0027)
- Group-based compartment access (ADR-0021)
- Event-sourced compartment content note; drop the Item domain
- Realtime broadcast of content-note updates to mobile
- Liveness healthcheck for the MQTT listener (`#52`)
- Show app version in the Filament sidebar footer
- Drop the dashboard, land on the Compartments list
- User name + email tooltip on the user-menu avatar
- Edit and clear compartment content notes from Filament (`#136`)
- Reset the provisioning token from the admin panel (`#110`)
- Bootstrap the first admin from configuration (`#141`, ADR-0044)
- Compartment list navigation (`#167`)
- Issue per-provisioning MQTT credential identities (`#161`, PR `#227`; ADR-0050)
- End-to-end OpenTelemetry tracing of the open flow (`#65`, PR `#189`; ADR-0042)
- Separate command acknowledgement from door-open detection (`#94`; ADR-0040)

**Fixes**
- Make event-sourced writes and synchronous projections atomic (`#139`, PR `#231`)
- Keep same-named locker banks in distinct compartment-list groups (PR `#234`)
- Repair status badges and align the two compartment views (`#190`)
- Hide revoked and expired users from the group member list (`#148`)
- Make last-admin role changes concurrency-safe (`#187`)
- Make command-response dedup crash-safe (`#188`)
- Harden archived-group behaviour
- Prevent duplicate compartment notifications
- Localize user-table action labels and the user-created notification (`#62`)
- Show the direct-users count in the compartment list (`#153`)
- Secure locale redirects and preserve query strings
- Enforce manager boundaries server-side; allow the managers group terms access
- Seed default role bindings only on fresh installs (ADR-0022)
- Type the reset-password success response and fix its message (`#86`)
- Public password-reset and email-verification fallbacks; unblock admin recovery
- Scope the `applyConfig` completeness check to the locker bank
- Align MQTT provisioning, `open_compartment` and `apply_config` payloads with the contract
- Dependency and security-advisory updates (Guzzle, CommonMark, postcss, vite, axios,
  socket.io-parser, Laravel framework, esbuild, shell-quote)

**Performance**
- Run Pint checks in parallel

### Mobile app

**Features**
- Android preview and unsigned iOS Simulator CI from `main` (`#19`, ADR-0055)
- Realtime compartment door state via Reverb (`#45`)
- Auto-logout on `401` with a session-expired message
- Content notes and the event-sourced compartment model
- Request-scoped API localization
- Locker rail and compartment card refinements; centralized theme and UI defaults
- Persisted in-app EN/DE language switcher
- Terms screen with dynamic document handling

**Fixes**
- Align the Expo patch version; align `expo-localization` with the SDK
- Set `ascAppId` and `APP_VARIANT` for tag-only non-interactive iOS TestFlight
  submits (`#19`)
- Correct logo and splash-screen scaling, with dark-mode support (`#93`)
- Prevent an empty-compartments flash on load
- Guard the reset-password response message
- Stop `baseApi` test timers leaking past Jest teardown
- Restore terms redirect semantics and copy

### Locker client

**Features**
- Hexagonal v2 rewrite, replacing v1 (`#175`)
- Batched door-sensor polling with change-only snapshots (`#166`)
- Recoverable MQTT command responses (`#171`)
- Apply runtime config from the server
- Publish a compartment snapshot after a successful open
- Validate persisted MQTT credentials with zod
- Watchtower-based update flow
- Log volume in `docker-compose`
- Contract-aligned fleet simulator with scenario files and traffic log (`#82`, ADR-0031)
- Require verified MQTTS for production broker URLs (`#163`, PR `#232`)

**Fixes**
- Recover the Modbus bus after prolonged disconnects (`#172`, PR `#228`)
- Make shutdown and the async lifecycle safe (`#170`)
- Complete runtime configuration handling with live slave IDs (`#174`)
- Improve protocol errors and observability (`#173`)
- Harden local persistence, persisted-state handling and credentials (`#168`)
- Harden MQTT response recovery (`#171`)
- Enforce the Modbus RTU inter-frame delay (ADR-0035)
- Serialize Modbus operations; poll state across all boards; poll configured channels
  individually; skip relay reads for configured snapshots
- Tolerate unreachable boards at startup
- Complete transaction-bound command dedup
- Share the in-flight connect promise, guard broker mismatch, register the message
  listener once, prevent concurrent heartbeat publishes
- Default MQTT broker URL; report missing MQTT credentials together
- Restore Waveshare flash commands; set the Watchtower Docker API version

### Website

- Landing page moved into the monorepo, deployed via GitHub Pages (`#53`)
- English localization under `/en/`, later swapped to the default locale (`#90`)
- Starlight documentation section, header aligned with the main site nav (`#92`)
- Bill of Materials migrated into the docs as a Hardware page
- Hardened Pages deployment workflow

### CI / tooling

- Add deployment-neutral public MQTTS through Traefik (`#163`, PR `#232`; ADR-0053)
- Separate component quality workflows; cover all backend changes (`#99`, partly)
- Update workflows to Node 24-compatible action releases (`#67`)
- Gate client releases on quality checks
- Cancel superseded pull-request runs
- Isolate Docker build caches; build one platform for client PRs
- Cut Android internal build time (`#204`; ADR-0047)
- Raise PHPStan to level 8 (`#199`)
- Decide the per-component release strategy (`#50`; ADR-0043)
- Laravel Boost for agent tooling (`#88`)

### Earlier (MVP and before)

Borrow / return / open routes and UI, Modbus communication in the backend (later moved
to the client), locker-instance selection in the app, the Docker build and GHCR
publishing pipeline, the Raspberry Pi setup guide, the initial MQTT API definition, the
Modbus simulator, and the first locker-client implementation.

---

## 3. Beta cut outcome and remaining validation

The [Beta release checklist](../release-checklist.md) remains the source of truth
for rollout and evidence.

### Source tags

The following tags were created on
`0365b8a5ff17e4e68f54293a4b939ba80e5843a4`, which was the `main` tip:

- `backend-v1.0.0-beta.1`
- `client-v1.0.0-beta.1`
- `mobile-v1.0.0-beta.1`

### Distribution outcome

- Backend and locker-client tag workflows completed successfully.
- The mobile quality gate, Android build, iOS build, and TestFlight submission
  completed successfully. Android store submission failed, so the mobile tag
  did not produce a complete cross-platform release.
- No GitHub Releases were published for these tags. The tags therefore identify
  the source baseline, but do not by themselves satisfy the release strategy's
  artifact and release-note requirements.

### Remaining Beta 2 work

- #242 — validate signing and the mobile build on physical devices, and complete
  Android distribution.
- #233 — verify MQTTS in the deployed environment.
- #169 — complete the Raspberry Pi and Modbus soak test.
- #134 — decide how stale `open_compartment` commands are rejected.

There is still no production deployment. The controlled pilot decision depends
on the field evidence above, not on source tags alone.

---

## References

- Monorepo release strategy — `#50` (ADR-0043)
- [Beta release checklist](../release-checklist.md)
- [Beta feature and change list — issue #209](https://github.com/Open-Locker/Open-Locker/issues/209)
- ADR-0032 — mobile internal test builds
- ADR-0036 — website in the monorepo
- Milestones: *Milestone 1 – Hardware MVP*, *Milestone 2 – Internal MVP*,
  *Milestone 3 – MVP*, *Beta*
