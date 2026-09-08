# Open-Locker agent map

This monorepo contains `locker-backend/` (Laravel API + Filament),
`mobile-app/` (Expo/React Native), `locker-client/` (Node/Raspberry Pi
MQTT-to-Modbus), `website/`, `hardware/`, and `docs/`.

Keep this file short. For detailed, conditional guidance, read the matching
skill under `.agents/skills/`:

- `open-locker-domain` for architecture, event sourcing, MQTT, Modbus, and ADRs.
- `backend-laravel` for Laravel, Filament, PHP, and backend verification.
- `mobile-app` for Expo, routes, i18n, Redux, and mobile checks.
- `api-contract-sync` for OpenAPI and generated mobile-client changes.
- `iot-hardware` for locker-client, MQTT, Modbus, and AsyncAPI changes.

Architecture-significant changes require an ADR in `docs/adr/`.
