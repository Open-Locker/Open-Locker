# Open-Locker Backend

Laravel 11 API backend for the Open-Locker project - an IoT-based locker sharing
system.

## Overview

This Laravel application provides:

- **REST API** for mobile app communication
- **Filament Admin Panel** for system administration
- **Modbus Integration** for IoT hardware control
- **OpenAPI Documentation** via Scramble

## Tech Stack

- **Framework:** Laravel 11
- **Admin Panel:** Filament 3.x
- **Authentication:** Laravel Sanctum
- **Database:** SQLite (development) / PostgreSQL (production)
- **Hardware:** Modbus TCP/RTU via libmodbus
- **Documentation:** Scramble OpenAPI Generator
- **PHP Version:** 8.4+

## Quick Start

### Prerequisites

- Docker & Docker Compose
- PHP 8.4+ with FFI extension
- Composer

### Installation

1. **Clone and setup:**
   ```bash
   git clone <repository-url>
   cd locker-backend
   composer install
   ```

2. **Environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Database:**
   ```bash
   php artisan migrate --seed
   ```

4. **Start development server:**
   ```bash
   php artisan serve
   ```

### Using Laravel Sail (Docker)

```bash
# Start containers
./vendor/bin/sail up -d

# Run migrations
./vendor/bin/sail artisan migrate --seed

# Access admin panel
# Visit: http://localhost/admin
```

## Development Guidelines

### Code Standards

- **PSR-12** coding standard
- `declare(strict_types=1);` in all PHP files
- Full class imports instead of FQCNs
- Comprehensive docblocks on all methods

### Architecture Patterns

- **Service Pattern** for business logic
- **Form Requests** for validation
- **JSON Resources** for API responses
- **Policies** for authorization
- **Feature Tests** preferred over Unit Tests

### Laravel Best Practices

- Use `artisan make:*` commands for boilerplate
- Keep controllers thin - delegate to services
- Use route model binding
- Mass assignment protection on models
- Migrations for schema changes

### Project-Specific Rules

- **API Only**: No public-facing views (except admin)
- **Modbus Safety**: Always use locks for hardware operations
- **OpenAPI**: Document all endpoints with Scramble
- **Testing**: Feature tests for workflows, mocks for hardware

## Key Components

### Models

- `User` - System users with admin capabilities
- `Item` - Physical objects available for borrowing
- `Locker` - Hardware-controlled storage compartments
- `ItemLoan` - Borrowing/returning transaction records

### Services

- `LockerService` - Hardware communication and lock management
- Modbus integration via custom FFI package

### Controllers

- `AuthController` - User authentication endpoints
- `ItemController` - Item management and borrowing
- `LockerController` - Hardware control and status
- `AdminController` - Administrative functions

### Admin Panel

- Filament resources for CRUD operations
- User management with admin privileges
- Item and locker monitoring
- System statistics and reporting

## API Documentation

### OpenAPI Specification

- **Generated documentation:** `/docs/api`
- **Specification file:** `api.json`
- **Auto-generated:** Via Scramble from Laravel code

### Key Endpoints

- `POST /api/auth/login` - User authentication
- `GET /api/items` - List available items
- `POST /api/items/{id}/borrow` - Borrow an item
- `POST /api/items/{id}/return` - Return an item
- `GET /api/lockers/status` - Get locker status

## Testing

### Running Tests

```bash
# All tests
composer test

# With coverage
composer test:coverage

# Parallel execution
composer test:parallel

# Specific test
composer test tests/Feature/ItemControllerTest.php
```

### Test Structure

- **Feature Tests** (preferred): End-to-end API testing
- **Unit Tests** (minimal): Isolated component logic
- **Factories**: Test data generation
- **Mocks**: Hardware service mocking

## Hardware Integration

### Modbus Configuration

```env
# TCP Configuration
MODBUS_DRIVER=tcp
MODBUS_TCP_IP=127.0.0.1
MODBUS_TCP_PORT=502

# RTU Configuration (alternative)
MODBUS_DRIVER=rtu
MODBUS_RTU_DEVICE=/dev/ttyUSB0
MODBUS_RTU_BAUD=9600
```

### Safety Features

- Connection locking prevents concurrent access
- Pulse-based locker opening (prevents damage)
- Automatic status polling and monitoring
- Error handling with graceful degradation

## Commands

### Artisan Commands

```bash
# Poll locker status (background service)
php artisan locker:poll-status

# Generate API documentation
php artisan scramble:export

# Run code style checks
php artisan pint
php artisan stan
```

### Composer Scripts

```bash
# Code quality
composer lint           # Run Pint (code style)
composer analyse        # Run PHPStan (static analysis)

# Testing
composer test           # Run all tests
composer test:coverage  # Run tests with coverage
composer test:parallel  # Run tests in parallel

# Documentation
composer export:api         # Export OpenAPI spec
composer generate:api-client # Generate Dart client
```

## Production Deployment

### Docker Configuration

- Multi-stage builds for optimization
- PHP 8.4 with FFI enabled
- Supervisor for background tasks
- Nginx reverse proxy

### Environment Variables

```env
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
MODBUS_LIB_PATH=/usr/lib/libmodbus.so.5
```

### Background Services

- `locker-poller` container for status monitoring
- Queue workers for background jobs
- Scheduled tasks for maintenance

## Contributing

1. Follow PSR-12 coding standards
2. Write Feature tests for new functionality
3. Update OpenAPI documentation
4. Use Conventional Commits for messages
5. Run `composer lint` and `composer analyse` before commits

## Cursor Rules

This project uses Cursor Rules for development guidance:

- **Scramble OpenAPI**: Documentation generation guidelines
- **Modbus Integration**: Hardware communication patterns
- **Domain Guidelines**: Business logic conventions
- **Testing Guidelines**: Feature test preferences

See `.cursor/rules/` for detailed guidelines.

## License

Open source under the MIT License.
