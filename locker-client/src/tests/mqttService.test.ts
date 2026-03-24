import assert from "node:assert/strict";
import test from "node:test";
import { prepareMQTTPayload } from "../services/mqttService";

test("prepareMQTTPayload adds message_id to object payloads", () => {
  const payload = prepareMQTTPayload({
    event: "heartbeat",
    data: {
      uptime_seconds: 5,
    },
  });

  const decoded = JSON.parse(payload) as {
    event: string;
    message_id?: string;
  };

  assert.equal(decoded.event, "heartbeat");
  assert.equal(typeof decoded.message_id, "string");
  assert.ok(decoded.message_id);
});

test("prepareMQTTPayload preserves existing message_id", () => {
  const payload = prepareMQTTPayload({
    message_id: "existing-id",
    action: "open_compartment",
  });

  const decoded = JSON.parse(payload) as {
    action: string;
    message_id?: string;
  };

  assert.equal(decoded.action, "open_compartment");
  assert.equal(decoded.message_id, "existing-id");
});

test("prepareMQTTPayload leaves string payloads unchanged", () => {
  const payload = prepareMQTTPayload("raw-payload");

  assert.equal(payload, "raw-payload");
});
