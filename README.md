# Open-Locker

[![Discord](https://img.shields.io/discord/1330191581273260113?style=flat-square&logo=discord&label=Discord&labelColor=%23FFF)](https://discord.gg/rZ74RYKN3H)

## The Project

This is an open source project to create software that locks and unlocks public
lockers to store and/or share items, sponsored by
[Smart City Hameln-Pyrmont](https://mitwirkportal.de/informieren).

### What we want to achieve

Within Hameln-Pyrmont, there is a set of lockers that the county uses to lend
objects like laptops or VR headset to interested citizens. This project is
supposed to improve the user experience and offer the county a way to
individualize the software to better suit their needs.

The group came together with the goal to improve their knowledge while building
something that will be of immediate use to the people around them.

### How you can help

You can join our weeklies on **Mondays and Tuesdays, alternating every week, at
19:30 CET/18:30 UTC** in our [Discord](https://discord.gg/rZ74RYKN3H), either to
listen in or to participate, or you can interact with us via github, sending us
pull requests, issues or general feedback. Our next weekly is on Tuesday, the
6th of May, followed by Monday, the 12th of May.

If you're still unsure where to start, you can always reach out to us in our
discord's text channels.

## Architecture

This is a **monorepo** containing multiple components:

- **Backend** (`locker-backend/`): Laravel 11 API with Filament admin panel
- **Mobile App** (`locker_app/`): Flutter app for end users
- **API Client** (`packages/locker_api/`): Auto-generated Dart client
- **Documentation** (`docs/`): Project architecture and guides

### System Overview

The system consists of:

- **IoT Hardware**: Raspberry Pi with Modbus communication to physical lockers
- **API Backend**: Laravel application managing items, users, and hardware
- **Mobile App**: Flutter app for borrowing and returning items
- **Admin Panel**: Filament-based web interface for system management

## Getting Started

For detailed installation and setup instructions, please see
[`docs/Installation.md`](docs/Installation.md).

## Component Documentation

### Backend (Laravel API)

Comprehensive documentation available in
[`locker-backend/README.md`](locker-backend/README.md):

- Development guidelines and coding standards
- API endpoints and OpenAPI documentation
- Hardware integration (Modbus) guidelines
- Testing strategies and best practices
- Deployment and production setup

### Mobile App (Flutter)

Documentation available in [`locker_app/README.md`](locker_app/README.md):

- Flutter app architecture
- State management and navigation
- API integration patterns
- Platform-specific builds

### Project Architecture

Detailed system architecture documentation in
[`docs/Architecture.md`](docs/Architecture.md):

- Component interaction diagrams
- Data flow and system boundaries
- Technology stack overview
- Hardware integration architecture

## Technology Stack

- **Backend**: Laravel 11, Filament 3.x, Sanctum, SQLite
- **Frontend**: Flutter, Dart, OpenAPI-generated client
- **Hardware**: Modbus TCP/RTU, libmodbus, FFI
- **Documentation**: Scramble OpenAPI, Mermaid diagrams
- **Development**: Docker, Laravel Sail, Cursor Rules

### Project Structure

```
Open-Locker/
├── locker-backend/     # Laravel API & Admin Panel
├── locker_app/         # Flutter Mobile App
├── packages/           # Shared packages
│   └── locker_api/     # Auto-generated API client
├── docs/               # Project documentation
└── docker-compose.yml  # Development environment
```

## Community

- **Discord**: [Join our Discord server](https://discord.gg/rZ74RYKN3H)
- **Issues**: Report bugs and request features via GitHub Issues
- **Contributing**: See component-specific documentation for guidelines

## Sponsorship

We welcome organizations interested in sponsoring this open source project.
Open-Locker aims to provide digital infrastructure for community resource
sharing and smart city initiatives. If your organization would like to support
this project or explore collaboration opportunities, please reach out to us via
[Discord](https://discord.gg/rZ74RYKN3H) or create an issue on GitHub.

Current sponsors help us:

- Develop and maintain the open source codebase
- Support community engagement and documentation
- Advance smart city digital infrastructure solutions
- Enable broader adoption of locker-sharing systems

## License

This project is open source under the MIT License. See [LICENSE](LICENSE) for
details.

## Acknowledgments

Sponsored by [Smart City Hameln-Pyrmont](https://mitwirkportal.de/informieren)
as part of their digital innovation initiative.
