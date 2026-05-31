# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Reality Check

This is a **monorepo** for the Open-Locker IoT locker-sharing system. The three
actively-developed components are:

- **`locker-backend/`** — Laravel 12 REST API + Filament admin panel. Source of truth for data, auth, and the OpenAPI spec. Uses **event sourcing**.
- **`mobile-app/`** — React Native (Expo, TypeScript) end-user app. Consumes a **TypeScript RTK Query client generated from the backend's OpenAPI spec**.
- **`locker-client/`** — TypeScript/Node service that runs on a Raspberry Pi (in Docker). Bridges the backend (via **MQTT**) and physical hardware (via **Modbus**).

Supporting: `hardware/` (KiCad designs), `docs/` (architecture + ADRs).

> ⚠️ **`README.md` and the Cursor rules are partially stale.** They describe a
> Flutter app (`mobile-app-legacy-flutter/`), a generated Dart client
> (`packages/locker_api/`), and a root `docker-compose.yml` — **none of these
> exist in the repo**. The live mobile client is the React Native app with a
> generated **TypeScript** client. Filament is **v5** (not 3.x). Trust the code
> over those docs.

## Architecture Big Picture

**Data flow:** `Mobile App → Laravel API → MQTT (Mosquitto) → locker-client on Pi → Modbus → physical lockers`. The admin panel (Filament) is the only server-rendered UI; everything else is API-first.

**Event sourcing (backend).** Domain state changes flow through `spatie/laravel-event-sourcing`. When working in the backend, expect this structure under `locker-backend/app/`:
- `Aggregates/` — command handlers that record events
- `StorableEvents/` — the persisted domain events
- `Projectors/` — build read models from events
- `Reactors/` — side effects (e.g. publishing MQTT messages) in response to events

Do **not** mutate read-model state directly when an aggregate/event path exists — record the event and let projectors/reactors react.

**MQTT contract.** The backend publishes via typed outbound publisher services in `locker-backend/app/Mqtt/` (see ADR-0008). Mosquitto authenticates clients against the Laravel API (`/api/mosq/*`) via `mosquitto-go-auth`. Message-id vs transaction-id separation is defined in ADR-0002. The `locker-client` subscribes/publishes on the other end.

**Modbus lives only in `locker-client/`, not the backend.** Hardware comms were moved out of Laravel (the old `php-modbus-ffi` dependency and `ModbusServiceProvider` were removed). The backend never speaks Modbus: `app/Services/LockerService.php` records an event, a Reactor publishes MQTT, and the `locker-client` (`src/modbus/`, using the `modbus-serial` package) issues the actual Modbus command. Modbus operations are serialized/lock-guarded and tolerate unreachable boards on the client side (ADR-0006/0007).

**The codegen pipeline is a real cross-component contract:**
1. The backend exposes the OpenAPI spec **live** via Scramble at `/docs/api.json` (generated from the controllers per request — there is no committed `api.json`).
2. Mobile app regenerates its client → `pnpm generate:api` in `mobile-app/` (RTK Query codegen, configured in `openapi-codegen.config.js`, which fetches the spec from the running backend at `EXPO_PUBLIC_API_BASE_URL`).

Changing an API response shape means regenerating the client **with the backend running**, or the mobile app's types drift. (See ADR for why we dropped the committed `api.json` in favor of the live URL.)

## Commands

**Package manager for JS projects is `pnpm`** (both `mobile-app/` and `locker-client/` have `pnpm-lock.yaml`).

### Backend (`locker-backend/`, Composer scripts)
```bash
composer dev              # Run server + queue + logs + vite concurrently (primary dev loop)
composer test             # All tests (php artisan test)
composer test:filter Name # Single test/filter — e.g. composer test:filter ItemControllerTest
composer test:parallel    # Parallel test run
composer test:coverage    # Tests with coverage
composer format           # Pint (PSR-12 autofix); format-check for CI
composer analyse          # PHPStan (level 6, 1G memory limit)
composer quality          # format-check + analyse + test (run before pushing)
composer export:api       # Dump OpenAPI spec to api.json (untracked; the spec is also served live at /docs/api.json)
php artisan locker:poll-status   # Background locker status poller
```
Dev runs on **PostgreSQL** (the `db` service in the Docker stack); `php artisan migrate --seed` for schema + test data. Sail (`./vendor/bin/sail`) wraps the Docker-based runs.

### Mobile App (`mobile-app/`)
```bash
pnpm start            # Expo dev client; start:go for Expo Go
pnpm ios | android    # Native builds
pnpm check            # typecheck + lint + format:check + expo-doctor (run before pushing)
pnpm test:ci          # Jest once (CI mode); pnpm test watches
pnpm generate:api     # Regenerate RTK Query client from the live backend OpenAPI URL (backend must be running)
pnpm typecheck        # tsc --noEmit
```

### Locker Client (`locker-client/`)
```bash
pnpm dev     # ts-node src/app.ts
pnpm build   # tsc
pnpm test    # builds, then runs node --test on dist
```
Runs in production as a Docker image (`ghcr.io/open-locker/locker-client:latest`); needs `config/locker-config.yml` and `.env` (`PROVISIONING_TOKEN`).

### Repo-level (`Justfile`)
```bash
just setup-mqtt      # Generate mosquitto.conf from template (.env MOSQ_HTTP_PASS) + restart MQTT container
just install-hooks   # Set core.hooksPath to .githooks (per-project pre-commit dispatch)
```

## Project-Specific Rules

**ADRs are mandatory for architecture-significant changes** (enforced via Cursor rules in `.cursor/rules/general/`). Create or update an ADR in `docs/adr/` — *even without an explicit request* — when a change touches any of:
- API contract / schema / external integration boundary
- MQTT topic structure or payload contract
- Modbus protocol, register mapping, or hardware communication strategy
- Infrastructure / hosting / runtime strategy
- Security, performance, reliability, or operability trade-offs
- Cross-component decisions (backend + mobile + IoT)

ADR format: numeric kebab-case (`docs/adr/NNNN-title.md`), one decision per ADR, sections in order: Title, Status, Date, Context, Decision, Alternatives Considered, Consequences, References. Never rewrite an accepted ADR — supersede it with a new one and link them. Reference the ADR path in commit/PR notes. `0000-template.md` is the starting point. This ADR requirement **overrides** the general "don't create docs unless asked" default.

### Backend conventions
- `declare(strict_types=1);` in all PHP files; PSR-12; full class imports (no inline FQCNs).
- Thin controllers → delegate to `app/Services/`. Form Requests for validation, JSON Resources for responses, Policies for authz.
- API-only (no public views except the Filament admin panel).
- Prefer Feature tests for workflows; mock hardware (Modbus) in tests.
- Document endpoints for Scramble so `export:api` stays accurate.

## Shared Agent Rules (single source of truth)

The team's rules live in `.cursor/rules/` and are shared with Cursor. They are
**imported** below so Claude Code reads the *same* files — edit the `.mdc`, never a
copy. When the team adds a brand-new rule file, add one matching `@import` line here
(or in the component `CLAUDE.md`). Existing-file edits sync automatically.

> ⚠️ Some imported rules are partially stale (see "Repository Reality Check" above:
> Filament is v5 not 3.x, no root `docker-compose.yml`, no Flutter app). Trust the
> code and this file's corrections over the imported rule text where they conflict.

Repo-wide rules:
@.cursor/rules/general/monorepo-architecture.mdc
@.cursor/rules/general/adr-decision-required.mdc
@.cursor/rules/general/adr-format-and-lifecycle.mdc
@.cursor/rules/general/documentation-precedence.mdc

Component-specific rules load from `locker-backend/CLAUDE.md` and
`mobile-app/CLAUDE.md` when you work in those directories.
