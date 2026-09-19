set minimum-version := "1.56.0"

# This file is the public command menu: keep portable one-command tasks here.
# Put branching, retries, file generation, and platform logic in scripts/.
# Matching .ps1/.sh entry points must keep the same inputs, defaults, output,
# mutation order, and failure behavior. If that duplication grows, move shared
# logic to an already-required cross-platform runtime instead of adding wrappers.
set dotenv-load := false

scripts_dir := justfile_directory() / "scripts"

# Explicit shells prevent Just's default `sh -cu` from becoming a hidden Windows
# dependency. Top-level conditional settings require Just 1.56 or newer.
[windows]
set shell := ["powershell.exe", "-NoLogo", "-NoProfile", "-Command"]

[unix]
set shell := ["bash", "-uc"]

default:
    @just --list

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
[windows]
setup-mqtt:
    @powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "{{ scripts_dir / 'setup-mqtt.ps1' }}"

[unix]
setup-mqtt:
    @bash "{{ scripts_dir / 'setup-mqtt.sh' }}"

# Start the stack with tracing enabled, using an existing trace backend.
[windows]
trace-overlay:
    @powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "{{ scripts_dir / 'tracing.ps1' }}" -Action overlay

[unix]
trace-overlay:
    @bash "{{ scripts_dir / 'tracing.sh' }}" overlay

# Start SigNoz and the stack with tracing enabled.
[windows]
trace-up:
    @powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "{{ scripts_dir / 'tracing.ps1' }}" -Action up

[unix]
trace-up:
    @bash "{{ scripts_dir / 'tracing.sh' }}" up

# Stop tracing while retaining SigNoz data.
[windows]
trace-down:
    @powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "{{ scripts_dir / 'tracing.ps1' }}" -Action down

[unix]
trace-down:
    @bash "{{ scripts_dir / 'tracing.sh' }}" down

# Check the local tracing stack.
[windows]
trace-status:
    @powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "{{ scripts_dir / 'tracing.ps1' }}" -Action status

[unix]
trace-status:
    @bash "{{ scripts_dir / 'tracing.sh' }}" status
