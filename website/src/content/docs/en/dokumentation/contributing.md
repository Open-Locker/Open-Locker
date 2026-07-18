---
title: Contributing
description: Repository layout, development workflow, the codegen pipeline, and how new documentation is added.
sidebar:
  order: 5
---

Contributions are welcome — code, hardware experience, or documentation. To
get started, grab an issue on
[GitHub](https://github.com/Open-Locker/Open-Locker/issues) or drop by the
weekly [Discord meetup](https://discord.gg/rZ74RYKN3H) (Tuesdays, 7:30 PM
CET).

## Monorepo layout

| Directory | Contents |
| --- | --- |
| `locker-backend/` | Laravel API + Filament admin (event sourcing) |
| `mobile-app/` | React Native app (Expo, TypeScript) |
| `locker-client/` | IoT client for the Raspberry Pi |
| `hardware/` | KiCad designs |
| `website/` | This website including documentation (Astro + Starlight) |
| `docs/` | Internal docs and architecture decision records (ADRs) |

## Quality checks before pushing

| Component | Command |
| --- | --- |
| Backend | `composer quality` (format + PHPStan + tests) |
| Mobile app | `pnpm check` and `pnpm test:ci` |
| Locker client | `pnpm check` and `pnpm test` |

## The codegen pipeline

The API contract is a real cross-component contract:

1. The backend serves the OpenAPI specification **live** at `/docs/api.json`
   (Scramble, generated from the controllers).
2. The mobile app generates its typed RTK Query client from it:
   `pnpm generate:api` (the backend must be running).

If you change an API response, you must regenerate the client — otherwise the
types drift.

## Architecture decisions (ADRs)

Architecture-significant changes (API contract, MQTT topics, Modbus,
infrastructure, security) require an ADR under `docs/adr/` — numbered, one
decision per ADR; accepted ADRs are never rewritten but superseded by new
ones.

## Contributing documentation

This documentation lives in the monorepo under `website/src/content/docs/` —
German under `dokumentation/`, English under `en/dokumentation/` (same
filenames = paired pages). Add new pages as Markdown with frontmatter
(`title`, `description`) and provide both languages. Local preview:
`pnpm dev` in `website/`.
