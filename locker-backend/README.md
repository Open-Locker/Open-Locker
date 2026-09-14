# Open-Locker Backend

The Open-Locker backend is a Laravel 12 application that provides the mobile
REST API, Filament 5 administration, event-sourced domain workflows, MQTT
coordination, and realtime client updates.

## Responsibilities

- Authenticate API clients with Laravel Sanctum.
- Manage users, roles, terms, groups, locker banks, compartments, content notes,
  and direct or group-based compartment access.
- Authorize compartment open requests and persist their progress as domain
  events and projected read models.
- Publish typed MQTT commands and process responses, device events, heartbeats,
  and compartment snapshots.
- Broadcast compartment updates through Laravel Reverb.
- Serve the Filament 5 admin panel.
- Generate the live OpenAPI contract with Scramble.

The backend does **not** communicate over Modbus. Physical control belongs to
[`locker-client`](../locker-client/README.md), which runs on the Raspberry Pi and
bridges MQTT to serialized Modbus RTU.

## Stack

- PHP 8.2+ and Laravel 12
- Filament 5
- PostgreSQL
- `spatie/laravel-event-sourcing`
- Laravel Sanctum
- Laravel Reverb
- Scramble
- `php-mqtt/laravel-client` with Mosquitto HTTP authentication

## Architecture

Domain state changes flow through aggregates in `app/Aggregates`, persisted
events in `app/StorableEvents`, and projectors in `app/Projectors`. Reactors in
`app/Reactors` handle follow-up work such as MQTT publication and Reverb
broadcasting. Eloquent models represent read models; do not update them directly
when an aggregate/event workflow exists.

The principal domain concepts are:

- **Locker banks** — provisioned on-site devices and their runtime
  configuration.
- **Compartments** — physical mapping, observed door state, and content notes.
- **Access** — assignments to individual users or groups.
- **Open requests** — authorization and hardware-command lifecycle.

See [the system architecture](../docs/Architecture.md) for component boundaries.

## Contracts

Scramble generates the OpenAPI specification live at `/docs/api.json`. This
runtime endpoint is the source used by mobile-app RTK Query code generation; an
exported `api.json` is not the canonical or committed contract.

Realtime subscriptions, event names, and fallback polling are documented in
[the app communication guide](../docs/app_communication.md). MQTT topics,
payloads, and operation directions are defined by
[the AsyncAPI contract](../docs/asyncapi/mqtt.yaml).

Keep these contracts synchronized when changing an endpoint, broadcast payload,
or MQTT message.

## Development

Installation and deployment instructions are maintained in
[the installation guide](../docs/Installation.md). Once dependencies and the
environment are ready, the main backend commands are:

```bash
composer dev
composer test
composer test:filter CompartmentControllerTest
composer test:parallel
composer test:coverage
composer format
composer analyse
composer quality
composer export:api
```

`composer export:api` is useful for inspecting an exported specification. The
mobile app normally generates against the running backend's live
`/docs/api.json` endpoint.

Backend code follows PSR-12 and uses `declare(strict_types=1);`. Keep controllers
thin, use Form Requests for validation, JSON Resources for responses, Policies
for authorization, and feature tests for workflows. MQTT and other external
side effects should be mocked at their boundaries in tests.

## Operations

The backend Compose stacks include PostgreSQL, queue workers, Mosquitto, the MQTT
listener, and Reverb. Operational configuration and deployment procedures live
in [the installation guide](../docs/Installation.md) rather than in this
component overview.

The MQTT listener consumes device traffic continuously; locker status is
reported through MQTT heartbeats, events, responses, and retained snapshots.
There is no backend Modbus status poller.

## License

Open-Locker is available under the [MIT License](../LICENSE).
