# Open-Locker System Architecture

## Overview

Das Open-Locker System ist ein IoT-basiertes Schließfachsystem, das aus mehreren
Komponenten besteht:

- **Backend**: Laravel 11 API mit Filament Admin-Panel
- **Frontend**: React Native Mobile App für Endnutzer (rewrite / target app)
- **Hardware**: Raspberry Pi mit Modbus-Kommunikation zu physischen
  Schließfächern
- **Documentation**: Automatische OpenAPI-Dokumentation mit Scramble

## Architecture Diagram

```mermaid
graph TB
    subgraph "Mobile App Layer"
        FlutterApp["Mobile App<br/>(React Native)"]
    end
    
    subgraph "API Layer"
        LaravelAPI["Laravel 11 API<br/>(locker-backend)"]
        AuthAPI["Authentication<br/>(Sanctum)"]
        ItemAPI["Item Management"]
        LockerAPI["Locker Control"]
        AdminAPI["Admin Functions"]
    end
    
    subgraph "Admin Interface"
        FilamentPanel["Filament Admin Panel<br/>(Web UI)"]
    end
    
    subgraph "Documentation"
        ScrambleDocs["Scramble OpenAPI<br/>(Auto-generated)"]
        StoplightUI["Stoplight Elements UI<br/>(/docs/api)"]
    end
    
    subgraph "Services Layer"
        LockerService["LockerService<br/>(Business Logic)"]
        AuthService["AuthController"]
        ItemService["ItemController"]
        AdminService["AdminController"]
    end
    
    subgraph "Data Layer"
        Models["Eloquent Models<br/>(User, Item, Locker, ItemLoan)"]
        PostgresDB["PostgreSQL Database<br/>(Docker)"]
    end
    
    subgraph "Hardware Communication"
        MqttBridge["MQTT Bridge<br/>(Mosquitto + Locker Client)"]
    end
    
    subgraph "IoT Hardware"
        RaspberryPi["Raspberry Pi / IoT Device"]
        ModbusUnits["Modbus Units<br/>(Multiple Lockers)"]
        PhysicalLockers["Physical Lockers<br/>(Hardware)"]
    end
    
    subgraph "Background Processes"
        PollingCommand["Locker Status Polling<br/>(artisan locker:poll-status)"]
        QueueWorker["Queue Worker<br/>(Future: Notifications)"]
    end
    
    subgraph "Container Environment"
        Docker["Docker Containers<br/>(Laravel Sail)"]
        Supervisor["Supervisor<br/>(Process Management)"]
    end
    
    %% API Communication
    FlutterApp -->|HTTP API Calls| LaravelAPI
    FilamentPanel -->|Web Interface| LaravelAPI
    LaravelAPI --> AuthAPI
    LaravelAPI --> ItemAPI
    LaravelAPI --> LockerAPI
    LaravelAPI --> AdminAPI
    
    %% Service Layer
    AuthAPI --> AuthService
    ItemAPI --> ItemService
    LockerAPI --> LockerService
    AdminAPI --> AdminService
    
    %% Data Access
    AuthService --> Models
    ItemService --> Models
    LockerService --> Models
    AdminService --> Models
    Models --> PostgresDB
    
    %% Hardware Communication (via MQTT + Locker Client)
    LockerService --> MqttBridge
    MqttBridge --> RaspberryPi
    RaspberryPi --> ModbusUnits
    ModbusUnits --> PhysicalLockers
    
    %% Background Services
    PollingCommand --> LockerService
    LockerService -->|Status Updates| Models
    
    %% Documentation
    LaravelAPI -->|Auto-generates| ScrambleDocs
    ScrambleDocs --> StoplightUI
    
    %% Container Management
    Docker --> LaravelAPI
    Docker --> PollingCommand
    Supervisor --> PollingCommand
    
    %% Styling
    classDef primary fill:#e1f5fe
    classDef secondary fill:#f3e5f5
    classDef hardware fill:#fff3e0
    classDef background fill:#e8f5e8
    
    class FlutterApp,LaravelAPI primary
    class FilamentPanel,ScrambleDocs secondary
    class RaspberryPi,ModbusUnits,PhysicalLockers hardware
    class PollingCommand,QueueWorker background
```

## Component Details

### Core Components

#### Laravel Backend (locker-backend/)

- **Framework**: Laravel 11 with PHP 8.4+
- **Authentication**: Laravel Sanctum für API-Token-basierte Authentifizierung
- **Database**: PostgreSQL (Docker) als Standard, SQLite für Tests/Kleininstallationen
- **Admin Panel**: Filament 3.x für administrative Aufgaben

#### Mobile App

- **Platform**: React Native (cross-platform)
- **Features (v1 focus)**: authentication, show accessible compartments, open/close + realtime feedback

#### Hardware Integration

- **Modbus Communication**: Über einen dedizierten Locker Client, der per Modbus mit der Hardware spricht
- **Protocols**: Sowohl Modbus TCP als auch RTU unterstützt
- **Hardware**: Raspberry Pi als IoT Gateway zu physischen Schließfächern

### Data Models

#### Core Entities

- **User**: Benutzer mit Admin-Rollen
- **Item**: Ausleihbare Gegenstände mit Bildern
- **Locker**: Physische Schließfächer mit Modbus-Adressen
- **ItemLoan**: Ausleihvorgänge mit Zeitstempel

#### Key Relationships

- Item ↔ Locker (1:1)
- User ↔ ItemLoan (1:N)
- Item ↔ ItemLoan (1:N)

### API Structure

#### Public Endpoints

- `GET /api/identify` - Service-Identifikation
- `POST /api/login` - Benutzeranmeldung
- `POST /api/register` - Benutzerregistrierung (Admin-only)

#### Authenticated Endpoints

- `GET /api/items` - Alle verfügbaren Items
- `POST /api/items/{item}/borrow` - Item ausleihen
- `POST /api/items/{item}/return` - Item zurückgeben
- `GET /api/items/loan-history` - Persönliche Ausleihhistorie

#### Admin Endpoints

- `GET /api/admin/users` - Benutzerverwaltung
- `GET /api/admin/lockers` - Schließfach-Übersicht
- `POST /api/admin/lockers/{locker}/open` - Manuelles Öffnen

### Background Services

#### Locker Status Polling

- **Command**: `artisan locker:poll-status`
- **Function**: Kontinuierliche Überwachung aller Schließfach-Status
- **Deployment**: Läuft als separater Docker-Container
- **Frequency**: 0.5 Sekunden Polling-Intervall

#### Queue System

- **Setup**: Laravel Queue für asynchrone Verarbeitung
- **Future**: E-Mail-Benachrichtigungen, Erinnerungen

### Development & Deployment

#### Development Environment

- **Laravel Sail**: Docker-basierte Entwicklungsumgebung
- **Services**: PHP 8.4, SQLite, Mailpit, Node.js
- **Commands**: `sail artisan`, `sail composer`, `sail npm`

#### Production Deployment

- **Container**: Multi-stage Docker-Build
- **Base**: ServerSideUp PHP-Images
- **Features**: FFI-Support, Modbus-Library, Supervisor

## Security Considerations

### API Security

- **Authentication**: Sanctum Token-based
- **Authorization**: Policy-basierte Zugriffskontrolle
- **Admin Protection**: AdminMiddleware für administrative Endpunkte

### Hardware Security

- **Network**: Modbus-Kommunikation über isoliertes Netzwerk
- **Access Control**: Locked-down Raspberry Pi mit minimalen Services
- **Monitoring**: Status-Polling für Anomalieerkennung

## Monitoring & Maintenance

### Health Checks

- **Laravel**: Built-in Health-Check-Endpoint (`/up`)
- **Hardware**: Modbus-Connection-Status über LockerService
- **Database**: Connection-Monitoring über Eloquent

### Logging

- **Application**: Laravel Log-Channels (single, daily, slack)
- **Hardware**: Modbus-Communication-Logs
- **Deployment**: Docker-Container-Logs

### Metrics

- **API**: Request/Response-Tracking
- **Hardware**: Locker-Status-Changes
- **Users**: Loan-Statistics über Admin-Panel
