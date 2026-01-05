default:
    @just --list

# Setup Mosquitto Authentication (generates mosquitto.conf from template)
setup-mqtt:
    #!/usr/bin/env bash
    set -e
    
    # Load variables from .env if present
    if [ -f locker-backend/.env ]; then
        export $(grep -v '^#' locker-backend/.env | xargs)
    fi


    PASS="${MOSQ_HTTP_PASS:-secret}"

    if [ -z "$PASS" ]; then
        echo "Error: MOSQ_HTTP_PASS not set in .env"
        exit 1
    fi

    echo "Generating Mosquitto config with Secret Token"

    # Path to config template and target
    TEMPLATE="locker-backend/mosquitto/mosquitto.conf.template"
    TARGET="locker-backend/mosquitto/mosquitto.conf"

    # Check if template exists
    if [ ! -f "$TEMPLATE" ]; then
        echo "Error: Template file $TEMPLATE not found!"
        exit 1
    fi

    # Replace __AUTH_PASS__ in template and write to target
    sed "s|__AUTH_PASS__|$PASS|g" "$TEMPLATE" > "$TARGET"

    echo "Config generated at $TARGET"
    echo "Restarting MQTT container..."
    docker compose -f locker-backend/docker-compose.yml restart mqtt

# Install git hooks
install-hooks:
    @echo "Setze Git Hooks..."
    @git config core.hooksPath .githooks
    @echo "Hooks wurden erfolgreich gesetzt!"
