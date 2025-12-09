# Installation

- [Installation](#installation)
  - [Architecture Overview](#architecture-overview)
  - [Cloud Backend Setup](#cloud-backend-setup)
  - [Locker Client Setup](#locker-client-setup)

The Open Locker System is split into multiple components. We use Docker to ensure a
consistent and easy installation process.

## Architecture Overview

The system consists of two main parts:

1. **Cloud Backend**: A central server (VPS) running:
   - Laravel Application (API + Admin Panel)
   - PostgreSQL Database
   - Mosquitto MQTT Broker (with HTTP Auth)
   - Redis & Workers

2. **Locker Client**: An IoT device (Raspberry Pi) at the physical locker location
   running:
   - Dockerized client software
   - Modbus communication to locker hardware

This guide provides instructions for setting up both components.

## Cloud Backend Setup

This setup is intended for a central server (e.g., VPS, Cloud Instance).

### 1. Initial Setup

Clone the repository and prepare the environment:

```bash
git clone https://github.com/Open-Locker/Open-Locker.git
cd Open-Locker
cp locker-backend/.env.example locker-backend/.env
```

Edit `locker-backend/.env` and configure:
- `APP_URL`: Your server's domain/IP
- `DB_PASSWORD`: Secure database password
- `MOSQ_HTTP_USER` & `MOSQ_HTTP_PASS`: Credentials for MQTT Broker <-> Backend communication

### 2. Configure MQTT Authentication

We use `mosquitto-go-auth` to authenticate MQTT clients against the Laravel API.

**Option A: Using `just` (Recommended)**

Use the provided task runner to generate the configuration automatically:

```bash
# Ensure you have 'just' installed
just setup-mqtt
```

**Option B: Manual Setup (No Shell Access/No Just)**

If you cannot use `just`, you must manually configure the Auth Header:

1. Generate the Base64 string of your credentials:
   ```bash
   echo -n "YOUR_USER:YOUR_PASS" | base64
   ```
2. Create `locker-backend/mosquitto/mosquitto.conf` by copying the example.
3. Add/Update the following line at the end of `mosquitto.conf`:
   ```conf
   auth_opt_http_extra_headers Authorization: Basic YOUR_BASE64_STRING
   ```
4. Restart the Mosquitto container.

### 3. Start Services

Start the backend stack using Docker Compose from the `locker-backend` directory:

```bash
cd locker-backend

# Development (local)
docker compose up -d

# Production (on server)
docker compose -f docker-compose.prod.yml up -d
```

### 4. Create Admin User

Once the services are running, create your first admin user:

```bash
docker compose exec app php artisan filament:user
```

You can now access the Admin Panel at `https://your-domain.com/admin`.

---

## Locker Client Setup (Raspberry Pi)

> 🚧 **Work in Progress**: The dedicated Locker Client application is currently under development.
> Detailed setup instructions and the client software package will follow in an upcoming release.
>
> Currently, the hardware integration logic resides within the backend codebase for testing purposes.

The Raspberry Pi serves as the local controller for the lockers. It connects to
the Cloud Backend via MQTT and controls the hardware via Modbus.

### Prerequisites

- Raspberry Pi 3/4/5 or Zero 2 W
- Raspberry Pi OS Lite (64-bit recommended)
- Docker & Docker Compose installed

### 1. Setup

Clone the repository on the Pi:

```bash
git clone https://github.com/Open-Locker/Open-Locker.git
cd Open-Locker/locker-client  # (Assuming client code path)
```

*(Note: If you are using the mono-repo, navigate to the appropriate client directory)*

### 2. Configuration

Create a `.env` file for the client:

```env
MQTT_BROKER_HOST=your-cloud-backend.com
MQTT_BROKER_PORT=1883
MQTT_USERNAME=provisioning_client  # Initial provisioning user
MQTT_PASSWORD=your_provisioning_password
LOCKER_ID=unique-locker-id
```

### 3. Modbus Hardware Configuration

Configure the hardware interface in `.env` depending on your connection type:

**Option A: Modbus TCP (Networked)**
```env
MODBUS_DRIVER=tcp
MODBUS_TCP_IP=192.168.1.100
MODBUS_TCP_PORT=502
```

**Option B: Modbus RTU (USB/Serial)**
```env
MODBUS_DRIVER=rtu
MODBUS_RTU_DEVICE=/dev/ttyUSB0
MODBUS_RTU_BAUD=9600
MODBUS_RTU_PARITY=N
MODBUS_RTU_DATA_BITS=8
MODBUS_RTU_STOP_BITS=1
```

### 4. Start Client

```bash
docker compose up -d
```

The client will connect to the Cloud Backend, authenticate, and listen for commands.
