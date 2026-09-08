---
name: backend-laravel
description: Use when working under locker-backend/ on Laravel, Filament, PHP, API resources, event-sourced workflows, or backend tests.
---

# Backend Laravel workflow

Read `locker-backend/composer.json` for the current versions; it is authoritative.
Use strict types, explicit return types, PSR-12, thin controllers, Form Requests,
Policies, Services, and JsonResources. Follow sibling code before introducing a
new pattern. Preserve event-sourcing boundaries and mock hardware-facing
services in feature tests.

Run backend commands from `locker-backend/` using the project’s available
Composer scripts. For meaningful PHP changes, run the affected PHPUnit test(s),
Pint, and PHPStan when dependencies are available. Do not invent Boost commands
or version facts that are absent from the installed project.

For API changes, also load `api-contract-sync`. For MQTT/Modbus changes, load
`iot-hardware` and `open-locker-domain`.
