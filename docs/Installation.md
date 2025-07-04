# Installation

- [Installation](#installation)
  - [Raspberry Pi](#raspberry-pi)
    - [Modbus Configuration](#modbus-configuration)

The Open Locker System is split up into multiple components. We try to use
standard parts and software to make the installation process as easy as
possible.

## Architecture Overview (New)

The new architecture consists of two main parts:

1. **Cloud Backend**: A central server running the Laravel application, a
   database, and the MQTT broker. This is the single source of truth.
2. **Locker Client**: A lightweight client running on an IoT device (like a
   Raspberry Pi) at the physical locker location.

This guide is split into instructions for these two parts.

## Cloud Backend Setup

This setup should be done on a central server (e.g., a VPS).

### 1. Initial Setup

Clone the repository and prepare the environment file:

```bash
git clone https://github.com/Open-Locker/Open-Locker.git
cd Open-Locker
cp .env.prod.example .env.prod
```

Edit the `.env.prod` file and generate a new `APP_KEY`:

```bash
openssl rand -base64 32
```

### 2. Start Services

Start all backend services using Docker Compose:

```bash
docker-compose up -d
```

This will start the Laravel application, the worker, and the Mosquitto MQTT
broker.

### 3. Mosquitto Production Configuration

For security reasons, the default passwords in
`docker/mosquitto/config/password.conf` must be changed and hashed for
production use.

**How to create secure users:**

1. Ensure the Mosquitto container is running (`docker-compose up -d mosquitto`).
2. Use `docker-compose exec` to run the `mosquitto_passwd` utility inside the
   container. This command will create a new password file and add the first
   user.
   ```bash
   docker-compose exec mosquitto mosquitto_passwd -c /mosquitto/config/password.conf -b laravel_backend your_very_secure_password_for_laravel
   ```
3. Add the `provisioning_client` user to the existing file.
   ```bash
   docker-compose exec mosquitto mosquitto_passwd -b /mosquitto/config/password.conf provisioning_client your_secure_password_for_provisioning
   ```
4. The `password.conf` file on your host machine will now contain the usernames
   with hashed passwords.
5. You should also update the `MQTT_PASSWORD` variable in your `.env.prod` file
   to match the password you set for the `laravel_backend` user.

## Locker Client Setup (Raspberry Pi)

The Open Locker System is designed to run on a Raspberry Pi, which serves as the
central controller for the locker system. The software components are
containerized using Docker, allowing for easy deployment and management of the
services.

To setup the Raspberry Pi use a Tool like
[Raspberry Pi Imager](https://www.raspberrypi.com/software/) to install the
latest version of Raspberry Pi OS Lite on a microSD card.

After the installation, you can use SSH to connect to the Raspberry Pi. Make
sure to enable SSH in the Raspberry Pi settings.

Install docker on the Raspberry Pi by following the instructions on the
[Docker website](https://docs.docker.com/engine/install/debian/).

After installing Docker, you can install Docker Compose by following the
instructions on the
[Docker Compose website](https://docs.docker.com/compose/install/).

Once Docker and Docker Compose are installed, you can clone the Open Locker
System repository and navigate to the project directory:

```bash
git clone https://github.com/Open-Locker/Open-Locker.git
cd Open-Locker
```

To configure the application, you need to create a `.env.prod` file in the
project directory. You can use the provided `.env.prod.example` file as a
template:

```bash
cp .env.prod.example .env.prod
```

You can then edit the `.env.prod` file to set the necessary environment
variables for your setup. The property `APP_KEY` is required and should be a
random string. You can generate a random key using the following command:

```bash
openssl rand -base64 32
```

### Modbus Configuration

Open Locker supports Modbus TCP and RTU.

The driver is selected using the `MODBUS_DRIVER` environment variable in the
`.env.prod` file. The available options are `tcp` for Modbus TCP and `rtu` for
Modbus RTU.

```bash
MODBUS_DRIVER=tcp
```

TCP Settings

You need to set the following environment variables in your `.env.prod` file for
Modbus TCP:

These are the default settings for Modbus TCP, you can change them according to
your setup:

```bash
MODBUS_TCP_IP=127.0.0.1
MODBUS_TCP_PORT=502
MODBUS_LIB_PATH=/lib/aarch64-linux-gnu/libmodbus.so.5
MODBUS_TCP_SLAVE=1
```

For Modbus RTU, you need to set the following environment variables in your
`.env.prod` file:

These are the default settings for Modbus RTU, you can change them according to
your setup:

```bash
MODBUS_RTU_DEVICE=/dev/ttyUSB0
MODBUS_RTU_BAUD=9600
MODBUS_RTU_PARITY=N
MODBUS_RTU_DATA_BITS=8
MODBUS_RTU_STOP_BITS=1
MODBUS_LIB_PATH=/lib/aarch64-linux-gnu/libmodbus.so.5
MODBUS_RTU_SLAVE=1
```

Next, you can start the services using Docker Compose:

```bash
docker-compose up -d
```

This command will start all the services defined in the `docker-compose.yml`
file in detached mode. You can check the status of the services using:

```bash
docker-compose ps
```

To stop the services, you can use:

```bash
docker-compose down
```

You can also check the logs of the services using:

```bash
docker-compose logs -f
```
