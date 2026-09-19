#!/usr/bin/env bash
# Paired with tracing.ps1. Keep actions, environment defaults, mutations,
# messages, and failures equivalent; derive every repository path from this file.
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
backend_compose="$root_dir/locker-backend/docker-compose.yml"
observability_compose="$root_dir/locker-backend/docker-compose.observability.yml"

if [[ -n "${SIGNOZ_DIR:-}" ]]; then
    signoz_home="$SIGNOZ_DIR"
elif [[ -n "${HOME:-}" ]]; then
    signoz_home="$HOME/.open-locker/signoz"
elif [[ -n "${USERPROFILE:-}" ]]; then
    signoz_home="$USERPROFILE/.open-locker/signoz"
else
    signoz_home="$root_dir/.open-locker/signoz"
fi

signoz_version="v0.99.0"
signoz_ui_port="${SIGNOZ_UI_PORT:-8085}"
otlp_http_port="${FORWARD_OTLP_HTTP_PORT:-4418}"
otlp_grpc_port="${FORWARD_OTLP_GRPC_PORT:-4417}"

test_url() {
    curl -sSf -o /dev/null "$1"
}

start_trace_overlay() {
    echo "Starting the stack with the tracing overlay..."
    FORWARD_OTLP_HTTP_PORT="$otlp_http_port" \
    FORWARD_OTLP_GRPC_PORT="$otlp_grpc_port" \
        docker compose \
            -f "$backend_compose" \
            -f "$observability_compose" \
            up -d
}

show_trace_status() {
    if test_url "http://localhost:$signoz_ui_port"; then
        echo "SigNoz UI              http://localhost:$signoz_ui_port"
    else
        echo "SigNoz UI              DOWN - run 'just trace-up'"
    fi

    if docker ps --filter name=otel-collector --filter status=running -q | grep -q .; then
        echo "Our collector          up (host ports $otlp_grpc_port/$otlp_http_port)"
    else
        echo "Our collector          DOWN - run 'just trace-up'"
    fi

    if docker compose -f "$backend_compose" exec -T app \
        printenv OTEL_EXPORTER_OTLP_ENDPOINT 2>/dev/null | grep -q .; then
        echo "Backend instrumented   yes"
    else
        echo "Backend instrumented   NO - run 'just trace-up'"
    fi

    echo
    echo "Locker client / simulator:"
    echo "  cd locker-client && OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:$otlp_http_port pnpm sim"
}

signoz_compose() {
    local deploy="$signoz_home/deploy/docker"
    local compose_file="$deploy/docker-compose.yaml"
    local override_file="$deploy/signoz-port-override.yml"
    local arguments=(compose -p signoz -f "$compose_file")

    if [[ ! -f "$compose_file" ]]; then
        echo "Error: SigNoz Compose file not found: $compose_file" >&2
        return 1
    fi

    if [[ -f "$override_file" ]]; then
        arguments+=(-f "$override_file")
    fi

    docker "${arguments[@]}" "$@"
}

case "${1:-status}" in
    overlay)
        start_trace_overlay
        ;;
    up)
        if [[ ! -d "$signoz_home/.git" ]]; then
            if [[ -e "$signoz_home" ]]; then
                echo "Error: SIGNOZ_DIR exists but is not a SigNoz Git checkout: $signoz_home" >&2
                exit 1
            fi

            echo "Cloning SigNoz $signoz_version into $signoz_home..."
            mkdir -p "$(dirname "$signoz_home")"
            git clone -b "$signoz_version" --depth 1 \
                https://github.com/SigNoz/signoz.git "$signoz_home"
        fi

        deploy="$signoz_home/deploy/docker"
        override_file="$deploy/signoz-port-override.yml"
        cat > "$override_file" <<EOF
services:
    signoz:
        ports: !override
            - "$signoz_ui_port:8080"
EOF

        echo "Starting SigNoz (UI on port $signoz_ui_port)..."
        signoz_compose up -d --remove-orphans
        start_trace_overlay

        echo "Waiting for SigNoz to answer..."
        ready=false
        for _ in {1..60}; do
            if test_url "http://localhost:$signoz_ui_port"; then
                ready=true
                break
            fi
            sleep 2
        done
        show_trace_status
        if [[ "$ready" != true ]]; then
            echo "Error: SigNoz did not become ready at http://localhost:$signoz_ui_port" >&2
            exit 1
        fi
        ;;
    down)
        docker compose -f "$backend_compose" up -d --remove-orphans
        if [[ -d "$signoz_home/.git" ]]; then
            signoz_compose stop
        else
            echo "SigNoz checkout not found; nothing to stop."
        fi
        echo "Tracing off. SigNoz data is kept."
        ;;
    status)
        show_trace_status
        ;;
    *)
        echo "Usage: $0 {overlay|up|down|status}" >&2
        exit 2
        ;;
esac
