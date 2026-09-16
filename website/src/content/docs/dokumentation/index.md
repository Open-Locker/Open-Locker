---
title: Overview
description: What Open Locker is, who it's for, and which components make up the system.
sidebar:
  order: 1
---

Open Locker is an open source project for public locker systems: software and
hardware blueprints for building, sharing, and managing digital lockers
yourself — for storing and lending items such as laptops, tools, or VR
headsets.

## Who is Open Locker for?

- **Municipalities and smart city projects** that want to run lending stations
- **Clubs, communities, and educational institutions** for whom commercial
  smart-locker solutions are too expensive
- **Makers and developers** who want to retrofit existing cabinets

## The components

| Component | Description |
| --- | --- |
| **Backend** | Laravel API with Filament admin panel — the source of truth for data, permissions, and commands |
| **Mobile app** | React Native app (Expo) for end users: view locker banks and open authorized compartments |
| **Locker client** | TypeScript service on a Raspberry Pi at the cabinet: receives commands via MQTT and drives Waveshare relay boards via Modbus RTU |
| **Hardware** | Blueprints and bill of materials for the electronics (relay boards, locks, wiring) |

How the parts play together is described in the
[Architecture](/dokumentation/architecture/).

## First steps

- [Getting started](/dokumentation/getting-started/) — set up a local development environment
- [Operations](/dokumentation/operations/) — production deployment and hosting
- [Contributing](/dokumentation/contributing/) — contribute to the project

## Community

Weekly meetup on [Discord](https://discord.gg/rZ74RYKN3H) every Tuesday at
7:30 PM Europe/Berlin time. Source code and issues on
[GitHub](https://github.com/Open-Locker/Open-Locker).
