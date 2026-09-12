# Open-Locker agent map

This monorepo contains `locker-backend/` (Laravel API + Filament),
`mobile-app/` (Expo/React Native), `locker-client/` (Node/Raspberry Pi
MQTT-to-Modbus), `website/`, `hardware/`, and `docs/`.

Keep this file short. For detailed, conditional guidance, read the matching
skill under `.agents/skills/` (mirrored by agent-specific symlinks):

- `open-locker-domain` for architecture, event sourcing, MQTT, Modbus, and ADRs.
- `backend-laravel` for Laravel, Filament, PHP, and backend verification.
- `blade-ui-structure` for Blade/Alpine view structure and component boundaries.
- `mobile-app` for Expo, routes, i18n, Redux, and mobile checks.
- `api-contract-sync` for OpenAPI and generated mobile-client changes.
- `iot-hardware` for locker-client, MQTT, Modbus, and AsyncAPI changes.
- `github-issue-workflow` for creating, updating, or splitting GitHub issues.
- `pr-writer` for creating or refreshing PR titles and descriptions.

Architecture-significant changes require an ADR in `docs/adr/`.
Check the source tree and package manifests before trusting older documentation.

For `website/`, keep the static Astro site accessible and free of backend
business logic. Use `website/package.json` scripts for verification.
