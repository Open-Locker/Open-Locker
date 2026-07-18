---
title: Mitmachen
description: Repository-Aufbau, Entwicklungs-Workflow, Codegen-Pipeline und wie neue Dokumentation entsteht.
sidebar:
  order: 5
---

Beiträge sind willkommen — von Code über Hardware-Erfahrungen bis
Dokumentation. Einstieg: ein Issue auf
[GitHub](https://github.com/Open-Locker/Open-Locker/issues) schnappen oder beim
wöchentlichen [Discord-Treffen](https://discord.gg/rZ74RYKN3H) (dienstags,
19:30 Uhr) vorbeischauen.

## Monorepo-Aufbau

| Verzeichnis | Inhalt |
| --- | --- |
| `locker-backend/` | Laravel-API + Filament-Admin (Event Sourcing) |
| `mobile-app/` | React-Native-App (Expo, TypeScript) |
| `locker-client/` | IoT-Client für den Raspberry Pi |
| `hardware/` | KiCad-Designs |
| `website/` | Diese Website inkl. Dokumentation (Astro + Starlight) |
| `docs/` | Interne Docs und Architecture Decision Records (ADRs) |

## Qualitäts-Checks vor dem Push

| Komponente | Befehl |
| --- | --- |
| Backend | `composer quality` (Format + PHPStan + Tests) |
| Mobile App | `pnpm check` und `pnpm test:ci` |
| Locker Client | `pnpm check` und `pnpm test` |

## Die Codegen-Pipeline

Der API-Vertrag ist ein echter Cross-Komponenten-Vertrag:

1. Das Backend serviert die OpenAPI-Spezifikation **live** unter
   `/docs/api.json` (Scramble, generiert aus den Controllern).
2. Die Mobile App generiert daraus ihren typisierten RTK-Query-Client:
   `pnpm generate:api` (Backend muss laufen).

Wer eine API-Response ändert, muss den Client regenerieren — sonst driften die
Typen.

## Architektur-Entscheidungen (ADRs)

Architektur-relevante Änderungen (API-Vertrag, MQTT-Topics, Modbus,
Infrastruktur, Security) brauchen einen ADR unter `docs/adr/` —
nummeriert, eine Entscheidung pro ADR, akzeptierte ADRs werden nie
umgeschrieben, sondern durch neue ersetzt.

## Dokumentation beitragen

Diese Dokumentation lebt im Monorepo unter `website/src/content/docs/` —
Deutsch unter `dokumentation/`, Englisch unter `en/dokumentation/` (gleiche
Dateinamen = gepaarte Seiten). Neue Seiten als Markdown mit
Frontmatter (`title`, `description`) anlegen und in beiden Sprachen ergänzen.
Lokale Vorschau: `pnpm dev` in `website/`.
