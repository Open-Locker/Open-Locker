#!/bin/sh
set -eu

CONF="/mosquitto/data/dynamic-security.json"
ADMIN="${MQTT_USERNAME:?Missing MQTT_USERNAME env}"
PASS="${MQTT_PASSWORD:?Missing MQTT_PASSWORD env}"
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

# 4) Idempotent setup of roles and provisioning client
mosquitto_ctrl -u "$ADMIN" -P "$PASS" dynsec createRole backend || true
mosquitto_ctrl -u "$ADMIN" -P "$PASS" dynsec addRoleACL backend publishClientSend locker/# true || true
mosquitto_ctrl -u "$ADMIN" -P "$PASS" dynsec addRoleACL backend subscribePattern  locker/# true || true
mosquitto_ctrl -u "$ADMIN" -P "$PASS" dynsec addClientRole "$ADMIN" backend || true

if [ -n "${MQTT_PROVISIONING_USERNAME:-}" ] && [ -n "${MQTT_PROVISIONING_PASSWORD:-}" ]; then
  mosquitto_ctrl -u "$ADMIN" -P "$PASS" dynsec createClient "$MQTT_PROVISIONING_USERNAME" || true
  mosquitto_ctrl -u "$ADMIN" -P "$PASS" dynsec setClientPassword "$MQTT_PROVISIONING_USERNAME" "$MQTT_PROVISIONING_PASSWORD" || true
  mosquitto_ctrl -u "$ADMIN" -P "$PASS" dynsec createRole provisioning || true
  mosquitto_ctrl -u "$ADMIN" -P "$PASS" dynsec addRoleACL provisioning publishClientSend locker/register/+ true || true
  mosquitto_ctrl -u "$ADMIN" -P "$PASS" dynsec addRoleACL provisioning subscribePattern  locker/provisioning/reply/+ true || true
  mosquitto_ctrl -u "$ADMIN" -P "$PASS" dynsec addClientRole "$MQTT_PROVISIONING_USERNAME" provisioning || true
fi

wait "$PID"


