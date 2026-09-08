# Open-Locker agent map

This is a monorepo for an IoT locker-sharing system. The live components are
`locker-backend/` (Laravel API + Filament admin), `mobile-app/` (Expo/React
Native), `locker-client/` (Node/Raspberry Pi MQTT-to-Modbus bridge), `website/`,
`hardware/`, and `docs/`.

Use the repository as the source of truth for versions and commands. Load the
focused skill that matches the work:

- `open-locker-domain` for architecture, event sourcing, MQTT, Modbus, and ADR triggers.
- `backend-laravel` for Laravel/Filament conventions and backend verification.
- `mobile-app` for Expo structure, i18n, Redux, generated API, and mobile checks.
- `api-contract-sync` when backend API schemas or generated mobile client code changes.
- `iot-hardware` when locker-client, MQTT, Modbus, AsyncAPI, or hardware changes.

Use the matching portable subagent for bounded analysis or review. Keep this
file short: detailed guidance belongs in `.agents/skills/` or `.agents/agents/`.

Architecture-significant changes require an ADR in `docs/adr/`; see the
`open-locker-domain` skill for the trigger list and lifecycle.
