set minimum-version := "1.56.0"
set dotenv-load := false

# Just is the command menu. pnpm runs the shared TypeScript workflows.

# Select the launching shell once; workflow logic is shared across platforms.
[windows]
set shell := ["powershell.exe", "-NoLogo", "-NoProfile", "-Command"]

[unix]
set shell := ["sh", "-uc"]

default:
    @just --list

# Install the pinned Node workflow dependencies (requires pnpm).
script-deps:
    pnpm --dir scripts install --frozen-lockfile

# Start the backend, database, Redis, MQTT, Mailpit, and Reverb services.
start:
    docker compose -f locker-backend/docker-compose.yml up -d

# Stop the local backend stack.
stop:
    docker compose -f locker-backend/docker-compose.yml down

# Show backend container status.
status:
    docker compose -f locker-backend/docker-compose.yml ps

# Start the locker simulator.
sim:
    pnpm --dir locker-client sim

# Install the repository-managed Git hooks.
install-hooks:
    git config core.hooksPath .githooks

# Generate Mosquitto configuration from the local backend .env file.
setup-mqtt:
    pnpm --dir scripts setup-mqtt

# Start the stack with tracing enabled, using an existing trace backend (Node 22+).
trace-overlay:
    pnpm --dir scripts trace overlay

# Start SigNoz and the stack with tracing enabled (Node 22+).
trace-up:
    pnpm --dir scripts trace up

# Stop tracing while retaining SigNoz data (Node 22+).
trace-down:
    pnpm --dir scripts trace down

# Check the local tracing stack (Node 22+).
trace-status:
    pnpm --dir scripts trace status
