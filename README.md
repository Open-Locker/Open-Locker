# Open-Locker

<p align="center">
  <a href="logo_open_locker.svg">
    <img src="logo_open_locker.svg" alt="Open-Locker Logo" width="160" />
  </a>
</p>

[![Discord](https://img.shields.io/discord/1330191581273260113?style=flat-square&logo=discord&label=Discord&labelColor=%23FFF)](https://discord.gg/rZ74RYKN3H)

## The Project

This is an open source project to build both the software and the hardware
blueprints/build guide (incl. a kit) for public lockers to store and/or share
items, sponsored by
[Smart City Hameln-Pyrmont](https://mitwirkportal.de/informieren).

### What we want to achieve

Within Hameln-Pyrmont, there is a set of lockers that the county uses to lend
objects like laptops or VR headset to interested citizens. This project is
supposed to improve the user experience and offer the county a way to
individualize the software to better suit their needs.

The group came together with the goal to improve their knowledge while building
something that will be of immediate use to the people around them.

### How you can help

You can join our weekly meeting **every Tuesday at 19:30 CET/18:30 UTC** in our
[Discord](https://discord.gg/rZ74RYKN3H), either to listen in or to participate,
or you can interact with us via github, sending us pull requests, issues or
general feedback.

If you're still unsure where to start, you can always reach out to us in our
discord's text channels.

## Architecture

This is a **monorepo** containing multiple components:

- **Backend** (`locker-backend/`): Laravel 11 API with Filament admin panel
- **Mobile App** (`mobile-app/`): React Native (Expo) app for end users
- **Legacy Mobile App** (`mobile-app-legacy-flutter/`): Previous Flutter app
- **API Client** (`packages/locker_api/`): Auto-generated Dart client
- **Hardware** (`hardware/`): KiCad designs and build kit references
- **Documentation** (`docs/`): Project architecture and guides

### System Overview

The system consists of:

- **IoT Hardware**: Raspberry Pi with Modbus communication to physical lockers
- **MQTT Broker**: Mosquitto with HTTP Authentication (via Laravel backend)
- **API Backend**: Laravel application managing items, users, and hardware
- **Mobile App**: React Native app for borrowing and returning items
- **Admin Panel**: Filament-based web interface for system management

## Getting Started

### Prerequisites

- Docker & Docker Compose
- [Just](https://github.com/casey/just) (Task Runner) - *Optional, but recommended*

### Installation

For detailed installation and setup instructions, including Cloud Backend and Locker Client setup, please see:

 **[`docs/Installation.md`](docs/Installation.md)**

## Component Documentation

### Backend (Laravel API)

Comprehensive documentation available in
[`locker-backend/README.md`](locker-backend/README.md):

- Development guidelines and coding standards
- API endpoints and OpenAPI documentation
- Hardware integration (Modbus) guidelines
- Testing strategies and best practices
- Deployment and production setup

### Mobile App (React Native)

Source available in [`mobile-app/`](mobile-app/):

- React Native/Expo app structure
- State management and navigation
- API integration patterns
- Platform-specific builds (iOS/Android)

### Project Architecture

Detailed system architecture documentation in
[`docs/Architecture.md`](docs/Architecture.md):

- Component interaction diagrams
- Data flow and system boundaries
- Technology stack overview
- Hardware integration architecture

## Technology Stack

- **Backend**: Laravel 11, Filament 3.x, Sanctum, SQLite
- **Frontend**: React Native (Expo), TypeScript
- **MQTT**: Mosquitto + mosquitto-go-auth (HTTP Backend)
- **Hardware**: Modbus TCP/RTU, libmodbus, FFI
- **Documentation**: Scramble OpenAPI, Mermaid diagrams
- **Development**: Docker, Laravel Sail, Cursor Rules, Just

### Project Structure

```
Open-Locker/
├── locker-backend/     # Laravel API & Admin Panel
├── mobile-app/         # React Native Mobile App
├── mobile-app-legacy-flutter/ # Legacy Flutter Mobile App
├── hardware/           # Hardware designs (KiCad) and related files
├── packages/           # Shared packages
│   └── locker_api/     # Auto-generated API client
├── docs/               # Project documentation
├── docker-compose.yml  # Development environment
└── Justfile            # Task runner configuration
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
