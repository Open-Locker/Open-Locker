# Beta Release — Feature and Change List

**Status:** draft (Beta milestone not yet cut)
**Date:** 2026-08-13
**Issue:** #209

## Beta goal

The goal of this Beta is to exercise the **core loop** end-to-end — authenticate, see
accessible compartments, open one, and watch door state update live — and to establish a
**baseline for collecting user feedback**. Feature completeness beyond that loop is
secondary; field hardening and release mechanics are what make the exercise trustworthy.

## Highlights (TL;DR)

- First tagged release of the monorepo (no prior tags or GitHub Releases).
- Core loop: sign in → list accessible compartments → open → live door state + content notes.
- Event-sourced compartment domain with auditable content notes (Item domain removed).
- Capability-based roles (incl. manager), group access, terms gate, EN/DE in app and admin.
- Canonical MQTT contract; locker-client v2 on Pi with Modbus, recovery, and fleet simulator.
- Mobile app on Expo with RTK Query codegen and Reverb realtime.
- Filament v5 ops-oriented admin: users, groups, compartments, open requests, audit log.
- Beta cut assumes open PRs listed below are merged first (stacked chain + related work).

## Scope

Nothing has ever been tagged or released — the repository has zero tags and zero GitHub
Releases — so the Beta is the **first release**. This document therefore has two parts:

1. **Feature list** — what the system does at Beta, by component. This is the
   "what do we have" answer.
2. **Change log** — what landed, grouped by component and derived from the Conventional
   Commit history (path-filtered per component) plus the issues and pull requests.

Per the monorepo release strategy (`#50`, PR `#195`; ADR pending renumber) the durable
form of this is generated per component into the GitHub Release attached to each tag
(`backend-vX.Y.Z`, `client-vX.Y.Z`, `mobile-vX.Y.Z`). This file is the one-off Beta list;
it is not a committed rolling `CHANGELOG.md`.

**Verification basis:** a feature counts as done if its code is merged into `dev` **or**
sits in an open pull request intended for the Beta cut. Work that exists only as an issue
is out of scope. Open PRs remain in the feature list below; cutting Beta assumes those
PRs (notably the stacked chain in §3) have merged.

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
- Compartment content notes — user-set, auditable, event-sourced (`#49`).
- Item domain dropped in favour of the content-note model.
- General audit log over the event store, browsable in the admin panel (`#109`).
- Crash-safe command-response dedup (`#188`).
- Compartment open requests recorded and queryable: an admin list, a per-locker-bank
  relation manager, and an open-status endpoint the app polls by command id.
- Group archiving instead of hard deletion, with archived-group behaviour hardened
  (`#105`, `#106`, `#149`).

**Access control**
- Capability-based roles and permissions, including a dedicated **manager** role, with
  manager boundaries enforced server-side (`#95`, ADR-0021).
- Group-based compartment access, effective access exposed via the API (`#46`, ADR-0020).
- User management in the Filament admin panel (`#18`).
- Role-permission management screens in the admin panel.
- Default role bindings seeded only on fresh installs.
- First admin bootstrapped from configuration (`#141`; first-admin bootstrap ADR pending
  renumber).
- Last-admin role changes made concurrency-safe (`#187`).

**Auth**
- Password reset consolidated into a public web auth flow, incl. admin-triggered reset
  mail from the Filament user UI (`#54`, `#63`, `#66`).
- Public email-verification fallback; email verification enforced for locker actions.
- Versioned terms workflow with an acceptance gate; terms document versions are managed
  in the admin panel and served to the app.
- Self-service profile and password editing, for both the app and the admin panel.
- First and last name captured for users (`#55`, ADR-0019).

**Admin panel (Filament v5)**
- Single panel with session-based locale switching, EN/DE throughout, incl. auth pages
  and door-state labels, with the nav locale switcher fixed (`#154`, `#155`, ADR-0029).
- Operations-oriented navigation: setup vs operations split, Users and Groups under an
  Access-management section, dashboard dropped in favour of the Compartments list
  (`#48`, `#126`).
- Operational compartment view with door state, direct-user counts and content notes
  (`#96`, `#153`).
- Admins can edit and clear compartment content notes (`#136`).
- Compartment list made easier to navigate, with status badges repaired and the two
  compartment views aligned (`#167`, `#190`).
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
  (`#128`, ADR-0028 synchronous projectors).

**Integration**
- Canonical MQTT contract: message taxonomy and wire contract, AsyncAPI spec and shared
  JSON Schemas, publishers and inbound handlers aligned, contract tests in both backend
  and client, contract validation in CI (`#72`–`#79`, ADR-0008).
- `message_id` for technical dedup and `transaction_id` for command correlation, with a
  complete dedup policy (`#39`, `#41`, ADR-0002).
- Retained compartment state snapshots; command responses aligned to the contract.
- Connection status derived from heartbeat telemetry, with a scheduled offline-locker
  detector; liveness healthcheck for the MQTT listener (`#52`, ADR-0025).
- `identify` endpoint so the app can confirm which backend instance it is talking to
  before login (`#12`).
- Mosquitto authenticates against the API via `mosquitto-go-auth` (`/api/mosq/*`).
- Request-scoped API localization via `Accept-Language` (ADR-0024).
- Live OpenAPI spec via Scramble at `/docs/api.json`; `api.json` no longer tracked
  (`#84`, ADR-0018).
- Realtime broadcasting to mobile over Reverb (door state, content notes)
  (`#45`, ADR-0022, ADR-0023), with door state verified end to end from MQTT snapshots
  and its projection updates fixed (`#44`, `#164`).
- End-to-end tracing and logging of the open flow with OpenTelemetry
  (`#65`, PR `#189`; OpenTelemetry ADR pending renumber).
- Command acknowledgement separated from door-open detection and alerting
  (`#94`; separate-ACK ADR pending renumber).

### Mobile app — React Native / Expo

- RTK Query client generated from the live backend OpenAPI spec.
- Compartment list and grid with open modal, live door state over Reverb, content notes.
- Sign-in, password reset and profile management; pre-login backend server switch.
- Terms modal flow with acceptance gate and bottom notices.
- Auto-logout on `401` with a session-expired message (`#60`).
- EN/DE localization with a persisted in-app language switcher; centralized theme and UI
  defaults, dark-mode-aware logo and splash scaling (`#93`, `#98`).
- Internal test builds from CI: EAS build + TestFlight submission from `main`
  (`#19`, ADR-0028).
- Legacy auth context and hand-written API layer removed (`#85`).
- react-doctor evaluated for code health, expo-doctor kept in the quality gate (`#64`).

### Locker client — Raspberry Pi / Node + Docker

- **v2 hexagonal rewrite** replacing v1 (`#175`, ADR-0024 locker-client v2).
- MQTT provisioning with persisted, zod-validated credentials and client-ID persistence.
- Command handling: `open_compartment`, `apply_config`, response ACKs, transaction-bound
  dedup, recoverable command responses (`#38`, `#40`, `#42`, `#171`).
- Modbus adapters for Waveshare relay boards: pulse/flash relay control, all relays off
  at startup, serialized operations, enforced RTU inter-frame delay, tolerance for
  unreachable boards (`#61`, ADR-0006, ADR-0007, ADR-0029).
- Door-sensor polling batched at 500 ms, publishing change-only snapshots
  (`#166`, ADR-0030).
- Runtime configuration applied from the server, with overlay validation.
- Heartbeat service for connection monitoring, plus an MQTT last-will message so the
  backend sees an ungraceful disconnect.
- Inbound protocol guard and a dedup store in front of command handling.
- Modbus reconnect coordinator that restores the bus after a dropped connection.
- Hardened local persistence and credential handling (`#168`).
- Improved protocol errors and observability (`#173`), with verbose runtime logging
  (`#113`).
- Contract-aligned locker fleet simulator: in-memory bus, credential and overlay stores,
  scenario files and a traffic log, so the whole stack runs without hardware
  (`#82`, `#23`, ADR-0027, `docs/simulator.md`).
- Watchtower-based update flow; ships as `ghcr.io/open-locker/locker-client:latest`.

### Hardware

- Test-locker Bill of Materials, EN and DE (`#47`).
- Circuit diagram and hardware plan (`#16`, `#26`).
- Flyback-diode protection for lock coils, integrated into the PCB (`#69`, `#80`).
- PCB mounting adapter model.

### Website and documentation

- Landing page moved into the monorepo, deployed to GitHub Pages at open-locker.org
  (`#53`, ADR-0030).
- English version of the site, URL-path-based localization (`#90`, ADR-0031).
- Starlight documentation section with aligned branding; BOM migrated in as a Hardware
  page (`#92`); copy and layout fine-tuning (`#179`).
- Architecture docs, MQTT architecture and integration plan, AsyncAPI specs,
  observability notes, installation and simulator guides, 43 ADRs.

### CI / infrastructure

- Per-component path-filtered workflows for backend, mobile app and locker client.
- Docker images for backend and client published to GHCR.
- Quality gates: Pint, PHPStan (level 8), Jest, `node --test`, expo-doctor (`#199`).
- Dependency-audit gates, with security advisories patched as they surfaced.
- Build-time work: isolated Docker caches, parallel Pint, superseded-run cancellation,
  single-platform client PR builds, Android internal build caching (`#204`; Android build
  cache ADR pending renumber).
- Per-component release strategy decided: independent tag namespaces, contract-tied
  SemVer, generated release notes (`#50`, PR `#195`; ADR pending renumber).
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
- Request-scoped API localization via `Accept-Language` (ADR-0024)
- Group-based compartment access (ADR-0020)
- Event-sourced compartment content note; drop the Item domain
- Realtime broadcast of content-note updates to mobile
- Liveness healthcheck for the MQTT listener (`#52`)
- Show app version in the Filament sidebar footer
- Drop the dashboard, land on the Compartments list
- User name + email tooltip on the user-menu avatar
- Edit and clear compartment content notes from Filament (`#136`)
- Reset the provisioning token from the admin panel (`#110`)
- Bootstrap the first admin from configuration (`#141`; first-admin bootstrap ADR pending
  renumber)
- Compartment list navigation (`#167`)
- End-to-end OpenTelemetry tracing of the open flow (`#65`, PR `#189`; OpenTelemetry ADR
  pending renumber)
- Separate command acknowledgement from door-open detection (`#94`; separate-ACK ADR
  pending renumber)

**Fixes**
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
- Seed default role bindings only on fresh installs (ADR-0021)
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
- Internal test build CI from `main`, EAS + TestFlight (`#19`)
- Realtime compartment door state via Reverb (`#45`)
- Auto-logout on `401` with a session-expired message
- Content notes and the event-sourced compartment model
- Request-scoped API localization
- Locker rail and compartment card refinements; centralized theme and UI defaults
- Persisted in-app EN/DE language switcher
- Terms screen with dynamic document handling

**Fixes**
- Align the Expo patch version; align `expo-localization` with the SDK
- Set `ascAppId` and `APP_VARIANT` for non-interactive iOS TestFlight submits (`#19`)
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
- Contract-aligned fleet simulator with scenario files and traffic log (`#82`, ADR-0027)

**Fixes**
- Improve protocol errors and observability (`#173`)
- Harden local persistence, persisted-state handling and credentials (`#168`)
- Harden MQTT response recovery (`#171`)
- Enforce the Modbus RTU inter-frame delay (ADR-0029)
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

- Separate component quality workflows; cover all backend changes (`#99`, partly)
- Gate client releases on quality checks
- Cancel superseded pull-request runs
- Isolate Docker build caches; build one platform for client PRs
- Cut Android internal build time (`#204`; Android build cache ADR pending renumber)
- Raise PHPStan to level 8 (`#199`)
- Decide the per-component release strategy (`#50`, PR `#195`; ADR pending renumber)
- Laravel Boost for agent tooling (`#88`)

### Earlier (MVP and before)

Borrow / return / open routes and UI, Modbus communication in the backend (later moved
to the client), locker-instance selection in the app, the Docker build and GHCR
publishing pipeline, the Raspberry Pi setup guide, the initial MQTT API definition, the
Modbus simulator, and the first locker-client implementation.

---

## 3. Beta readiness — finish, consolidate, tighten

Ordered by what would stop the release, not by effort.

### Would block shipping

**Land the stacked PR chain (#183 → #210).** Fourteen of the features listed above sit
in one bottom-up stack, plus #120 separately. Nothing in it is released until the whole
chain merges, and every day it stays open the rebase cost grows. This is the single
biggest risk to the Beta date.

**Resolve or replace PR #107 (`dev` → `main`).** It is open since 2026-06-11, marked
`CONFLICTING`, and carries +145,808/−9,447. Meanwhile `main` last moved on 2026-07-20
and is 245 commits behind `dev`. Beta ships from `main`, so this is a hard gate. A fresh
merge PR is likely cheaper than salvaging this one.

**Roll out the monorepo release strategy (`#50`, PR `#195`) before cutting anything.**
There are still zero tags and zero Releases, so today there is no way to say "this Pi
runs Beta" or to roll back. The open ADR's own rollout list also flags that
`workflow_dispatch` on `docker-ghcr.yml` publishes `latest` from whatever branch it runs
on — a dispatch from `dev` would overwrite production. Fix that before the first tag,
not after.

**MQTT TLS (#163).** `mosquitto.conf.template` defines only `listener 1883` with
`auth_opt_http_with_tls false`. Broker credentials and open commands cross the network in
clear. This is the one security item worth hard-blocking on: a Beta runs on someone
else's network.

**Soak-test the v2 client on a Pi (#169).** v1 was replaced wholesale. The rewrite is
well covered by tests, but nothing here shows it running for days against real Modbus
hardware. Beta is exactly where an undetected slow leak or a wedged bus shows up.

**Make client shutdown and async lifecycle safe (#170).** An unclean stop in the middle
of a Modbus write is how a relay gets left energised. Cheap to fix, unpleasant to
discover in the field.

### Consolidate

**Fix the duplicate ADR numbers.** 0018, 0023, 0024, 0025, 0028, 0029 and 0030 are each
used by two different ADRs. Commit messages and PR bodies reference ADRs by number, so
those references are currently ambiguous. Renumbering is trivial now and gets harder
with every new ADR.

**Promote the ADRs that describe shipped behaviour.** On the open stacked chain, the
ADRs for separate ACK (`#94`), OpenTelemetry (`#65` / PR `#189`), monorepo release
strategy (`#50` / PR `#195`), first-admin bootstrap (`#141`), and Android build caching
(`#204`) are still `Proposed`, though their code is merged or in an open PR — including
the release strategy the cut itself depends on. (On current `dev`, bare numbers 0031–0033
already mean different Accepted decisions, so cite those stack ADRs by issue/PR until
renumber lands.) A Proposed ADR describing what the system already does trains people to
ignore the status field.

**Close the issues whose work already shipped** — #109 (audit log), #52 (healthcheck),
#122 (group memberships), plus the stack's issues as it merges. The milestone should
read true on release day, and right now it undercounts what is done.

**Refactor the CI workflows (#99).** It is the last structural CI item. Doing it after
the stack lands means touching six workflows once instead of twice.

### Remove

**`TODO.md`.** A German checklist from the pre-MQTT architecture rebuild, with every box
already ticked. It is superseded by the ADRs and the issue tracker, and a stale roadmap
in the repo root is the first thing a new contributor reads.

**The stale-docs warning in `CLAUDE.md`.** It still calls out a Flutter app and Dart
client that the README no longer describes. The root `docker-compose.yml` mention is
partly still fair — the README tree still lists one — so trim the Flutter/Dart half of
the warning rather than deleting the whole note.

### Decide before calling it Beta

**What "Beta" means for distribution.** `eas.json` submits Android to the `internal`
track and builds an APK, and iOS goes to TestFlight internally (#19). That is a team
build, not a beta programme. If external testers are the point, this needs a closed or
open testing track and an AAB — worth settling before the tag, since store review is the
slowest link.

**Stale `open_compartment` commands (#134).** Still an open discussion. A command
replayed after a long delay opens a physical door with nobody expecting it. Beta is the
right moment to decide, because it is the first time real doors are involved.

**Where OpenTelemetry traces go.** PR #189 instruments the open flow end to end, but no
collector or backend is deployed or documented. Instrumentation with nowhere to send
data is cost without benefit — and the open flow is precisely what you want traced when
a Beta site reports "the door didn't open".

**Runtime config completeness (#174).** Left open, and it determines how much a locker
can be reconfigured without a redeploy — which is what you want during a Beta, when
settings change often and physical access is inconvenient.

### Not missing, worth stating

No gaps found in the core loop: authenticate, see accessible compartments, open one,
watch its door state update live, with the whole path audited and event-sourced. The
admin side covers users, groups, roles, access grants, compartments, open requests,
terms and an audit log, in EN and DE. For a Beta, the feature set is there — the risk is
concentrated in release mechanics and field hardening, not in missing product.

---

## References

- Monorepo release strategy — `#50`, PR `#195` (ADR pending renumber; not the ADR-0033
  persistence file on current `dev`)
- ADR-0028 — mobile internal test builds
- ADR-0030 — website in the monorepo
- Milestones: *Milestone 1 – Hardware MVP*, *Milestone 2 – Internal MVP*,
  *Milestone 3 – MVP*, *Beta*
