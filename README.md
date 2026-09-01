# Open-Locker

<p align="center">
  <a href="logo_open_locker.svg">
    <img src="logo_open_locker.svg" alt="Open-Locker Logo" width="160" />
  </a>
</p>

[![Discord](https://img.shields.io/discord/1330191581273260113?style=flat-square&logo=discord&label=Discord&labelColor=%23FFF)](https://discord.gg/rZ74RYKN3H)

Open-Locker is an open source software and hardware platform for public lockers
used to store and share resources. The project includes the cloud services,
mobile experience, on-site controller, public website, and hardware blueprints
needed to run a deployment.

The project began with lockers operated by the county of Hameln-Pyrmont for
lending equipment such as laptops and VR headsets. It is sponsored by
[Smart City Hameln-Pyrmont](https://mitwirkportal.de/informieren) and developed
by a community learning together while building useful public infrastructure.

## How the system works

The React Native mobile app uses the Laravel API to discover accessible
compartments, update their content notes, and request that a compartment open.
The backend authorizes and records those operations through event-sourced domain
workflows. It sends hardware commands over MQTT to a Raspberry Pi running
`locker-client`; only that client communicates with the relay boards over
serialized Modbus RTU. Laravel Reverb carries compartment updates; the current
mobile app applies live door-state and content-note changes.

See [the architecture overview](docs/Architecture.md) for system boundaries,
[the app communication guide](docs/app_communication.md) for REST and realtime
behavior, and [the AsyncAPI contract](docs/asyncapi/mqtt.yaml) for MQTT topics
and payloads.

## Repository structure

```text
Open-Locker/
├── locker-backend/  # Laravel 12 API, Filament 5 admin, MQTT, and Reverb
├── locker-client/   # Raspberry Pi MQTT-to-Modbus RTU bridge and simulator
├── mobile-app/      # React Native and Expo client
├── website/         # Astro public website and published documentation
├── hardware/        # KiCad designs and build references
├── docs/            # Architecture, contracts, ADRs, and operating guides
└── Justfile         # Repository task runner
```

The backend runs on PostgreSQL and exposes a live Scramble-generated OpenAPI
document at `/docs/api.json`. The mobile app generates its RTK Query client from
that live contract.

## Getting started

Use [the installation guide](docs/Installation.md) for backend and Raspberry Pi
setup. Component-specific details live in:

- [Backend documentation](locker-backend/README.md)
- [Locker client documentation](locker-client/README.md)
- [Website documentation](website/README.md)
- [Hardware-free simulator guide](docs/simulator.md)

## Community

Join our weekly meeting every Tuesday at **19:30 CET / 18:30 UTC** on
[Discord](https://discord.gg/rZ74RYKN3H), or participate through GitHub issues,
pull requests, and feedback. New contributors are welcome to listen in, ask
questions, or choose a component that interests them.

We also welcome organizations interested in sponsoring or adopting open digital
infrastructure for community resource sharing. Contact the project through
Discord or open a GitHub issue.

## License

Open-Locker is available under the [MIT License](LICENSE).
