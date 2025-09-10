#!/bin/sh
set -eu

CONF="/mosquitto/data/dynamic-security.json"
# Dynsec admin credentials (separate from application/backend user)
ADMIN="${MQTT_DYNSEC_USERNAME:-${MQTT_USERNAME:?Missing MQTT_USERNAME env}}"
PASS="${MQTT_DYNSEC_PASSWORD:-${MQTT_PASSWORD:?Missing MQTT_PASSWORD env}}"
# Optional backend application user (created and assigned to the backend role)
BACKEND_USER="${MQTT_BACKEND_USERNAME:-}"
BACKEND_PASS="${MQTT_BACKEND_PASSWORD:-}"
HOST="127.0.0.1"
PORT="${BROKER_PORT:-1883}"

# 1) Init dynsec json (no broker needed)
if [ ! -f "$CONF" ]; then
  printf "%s\n%s\n" "$PASS" "$PASS" | mosquitto_ctrl dynsec init "$CONF" "$ADMIN"
fi

# 2) Start mosquitto in background
mosquitto -c /mosquitto/config/mosquitto.conf &
PID=$!

# 3) Wait until broker is up
for i in $(seq 1 60); do
  nc -z "$HOST" "$PORT" && break || sleep 1
done

# 4) Idempotent setup of roles and clients
mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec createRole backend || true
mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec addRoleACL backend publishClientSend locker/# allow 0 || true
mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec addRoleACL backend subscribePattern locker/# allow 0 || true

# Create backend client (distinct from dynsec admin) and assign backend role
if [ -n "${BACKEND_USER}" ] && [ -n "${BACKEND_PASS}" ]; then
  mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec createClient "$BACKEND_USER" || true
  mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec setClientPassword "$BACKEND_USER" "$BACKEND_PASS" || true
  mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec addClientRole "$BACKEND_USER" backend || true
fi

if [ -n "${MQTT_PROVISIONING_USERNAME:-}" ] && [ -n "${MQTT_PROVISIONING_PASSWORD:-}" ]; then
  mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec createClient "$MQTT_PROVISIONING_USERNAME" || true
  mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec setClientPassword "$MQTT_PROVISIONING_USERNAME" "$MQTT_PROVISIONING_PASSWORD" || true
  mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec createRole provisioning || true
  mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec addRoleACL provisioning publishClientSend locker/register/+ allow 0 || true
  mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec addRoleACL provisioning subscribePattern locker/provisioning/reply/+ allow 0 || true
  mosquitto_ctrl -h "$HOST" -p "$PORT" -u "$ADMIN" -P "$PASS" dynsec addClientRole "$MQTT_PROVISIONING_USERNAME" provisioning || true
fi

wait "$PID"


