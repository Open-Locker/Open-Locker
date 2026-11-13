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

# Where the SigNoz checkout lives. Must survive reboots and temp cleanup: its
# containers bind-mount config out of this directory, so if it disappears they
# can never be restarted again.
signoz_dir := env_var_or_default("SIGNOZ_DIR", env_var("HOME") / ".open-locker/signoz")
signoz_version := "v0.99.0"
# SigNoz wants 8080, which a local Apache/nginx usually already owns.
signoz_ui_port := env_var_or_default("SIGNOZ_UI_PORT", "8085")
# SigNoz publishes OTLP on 4317/4318, so our collector moves out of the way.
otlp_http_port := env_var_or_default("FORWARD_OTLP_HTTP_PORT", "4418")
otlp_grpc_port := env_var_or_default("FORWARD_OTLP_GRPC_PORT", "4417")

# Backend-agnostic: exports to whatever locker-backend/docker/otel-collector.yml
# targets. Use this when you run your own Jaeger/Tempo/Honeycomb — point the
# collector at it first, then bring the overlay up.
#
# Start the stack with tracing on (bring your own trace backend)
trace-overlay:
    #!/usr/bin/env bash
    set -euo pipefail

    echo "Starting the stack with the tracing overlay..."
    FORWARD_OTLP_HTTP_PORT={{ otlp_http_port }} \
    FORWARD_OTLP_GRPC_PORT={{ otlp_grpc_port }} \
        docker compose \
            -f locker-backend/docker-compose.yml \
            -f locker-backend/docker-compose.observability.yml \
            up -d

# Start SigNoz + the tracing overlay (see docs/observability.md)
trace-up:
    #!/usr/bin/env bash
    set -euo pipefail

    if [ ! -d "{{ signoz_dir }}" ]; then
        echo "Cloning SigNoz {{ signoz_version }} into {{ signoz_dir }}..."
        mkdir -p "$(dirname "{{ signoz_dir }}")"
        git clone -b {{ signoz_version }} --depth 1 \
            https://github.com/SigNoz/signoz.git "{{ signoz_dir }}"
    fi

    DEPLOY="{{ signoz_dir }}/deploy/docker"
    cat > "$DEPLOY/signoz-port-override.yml" <<EOF
    services:
        signoz:
            ports: !override
                - "{{ signoz_ui_port }}:8080"
    EOF

    echo "Starting SigNoz (UI on port {{ signoz_ui_port }})..."
    docker compose -p signoz \
        -f "$DEPLOY/docker-compose.yaml" \
        -f "$DEPLOY/signoz-port-override.yml" \
        up -d --remove-orphans

    just trace-overlay

    echo "Waiting for SigNoz to answer..."
    for _ in $(seq 1 60); do
        if curl -sfo /dev/null "http://localhost:{{ signoz_ui_port }}"; then break; fi
        sleep 2
    done

    just trace-status

# Stop tracing: drop the overlay, leave the stack running
trace-down:
    #!/usr/bin/env bash
    set -euo pipefail
    echo "Bringing the stack up without the overlay..."
    docker compose -f locker-backend/docker-compose.yml up -d --remove-orphans
    echo "Stopping SigNoz..."
    docker compose -p signoz stop
    echo "Tracing off. SigNoz data is kept; 'just trace-up' brings it back."

# Is tracing actually wired up end to end?
trace-status:
    #!/usr/bin/env bash
    set -uo pipefail

    printf '%-22s ' "SigNoz UI"
    if curl -sfo /dev/null "http://localhost:{{ signoz_ui_port }}"; then
        echo "http://localhost:{{ signoz_ui_port }}"
    else
        echo "DOWN — run 'just trace-up'"
    fi

    printf '%-22s ' "Our collector"
    if docker ps --filter name=otel-collector --filter status=running -q | grep -q .; then
        echo "up (host ports {{ otlp_grpc_port }}/{{ otlp_http_port }})"
    else
        echo "DOWN — run 'just trace-up'"
    fi

    # The failure that looks like everything is fine: containers up, but the app
    # was recreated without the overlay, so it exports nothing.
    printf '%-22s ' "Backend instrumented"
    if docker compose -f locker-backend/docker-compose.yml exec -T app \
        printenv OTEL_EXPORTER_OTLP_ENDPOINT 2>/dev/null | grep -q .; then
        echo "yes"
    else
        echo "NO — app has no OTEL_* env; run 'just trace-up'"
    fi

    echo
    echo "Locker client / simulator:"
    echo "  cd locker-client && OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:{{ otlp_http_port }} pnpm sim"
