#!/bin/bash

# A shell script to test the MQTT provisioning flow using native mosquitto tools.
# This bypasses the php-mqtt-client library to isolate the test and provide a reliable client.

set -e

if [ -z "$1" ]; then
  echo "Error: Provisioning token is required."
  echo "Usage: ./test-provisioning.sh <provisioning-token>"
  exit 1
fi

# --- Configuration ---
TOKEN="$1"
# Generate a unique ID for this test run to match the ACL rule (%c).
CLIENT_ID="shell-tester-$(date +%s)-${RANDOM}" 
REGISTER_TOPIC="locker/register/${TOKEN}"
REPLY_TOPIC="locker/provisioning/reply/${CLIENT_ID}"
PAYLOAD="{\"client_id\":\"${CLIENT_ID}\"}"

MQTT_HOST="mosquitto"
MQTT_USER="provisioning_client"
# WARNING: This password must match the one in your .env for MQTT_PROVISIONING_PASSWORD
MQTT_PASS="a_public_password"


# --- Execution ---
echo "--- MQTT Provisioning Test ---"
echo "Host:           ${MQTT_HOST}"
echo "Client ID:      ${CLIENT_ID}"
echo "Reply Topic:    ${REPLY_TOPIC}"
echo "------------------------------"
echo ""

# We run the subscriber in the background first, so it's ready to catch the reply.
echo "<<< Starting subscriber in the background..."
mosquitto_sub \
  -h "${MQTT_HOST}" \
  -t "${REPLY_TOPIC}" \
  -u "${MQTT_USER}" \
  -P "${MQTT_PASS}" \
  -i "${CLIENT_ID}" \
  -v \
  -C 1 &
SUB_PID=$!

# Give the subscriber a moment to connect and subscribe.
sleep 1

echo ">>> Publishing registration request..."
echo "    Topic: ${REGISTER_TOPIC}"
echo "    Payload: ${PAYLOAD}"
# The publisher uses a slightly different client ID to avoid any potential self-delivery issues.
mosquitto_pub \
  -h "${MQTT_HOST}" \
  -t "${REGISTER_TOPIC}" \
  -m "${PAYLOAD}" \
  -u "${MQTT_USER}" \
  -P "${MQTT_PASS}" \
  -i "pub-${CLIENT_ID}"

echo ""
echo "Waiting for response from subscriber..."

# Wait for the background subscriber process to finish (it will exit after 1 message due to -C 1)
wait $SUB_PID

echo ""
echo "--- Test Complete ---"
