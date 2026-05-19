import assert from "node:assert/strict";
import test from "node:test";
import type { MqttClient } from "mqtt";
import { mqttClientManager } from "../mqtt/mqttClientManager";
import { MQTTService, prepareMQTTPayload } from "../services/mqttService";

test("prepareMQTTPayload adds message_id to heartbeat-style payloads", () => {
  const payload = prepareMQTTPayload({
    timestamp: "2026-01-01T00:00:00.000Z",
    uptime_seconds: 5,
  });

  const decoded = JSON.parse(payload) as {
    timestamp?: string;
    uptime_seconds?: number;
    message_id?: string;
    state?: string;
  };

  assert.equal(decoded.timestamp, "2026-01-01T00:00:00.000Z");
  assert.equal(decoded.uptime_seconds, 5);
  assert.equal(typeof decoded.message_id, "string");
  assert.ok(decoded.message_id);
  assert.equal(decoded.state, undefined);
});

test("prepareMQTTPayload adds timestamp to object payloads", () => {
  const payload = prepareMQTTPayload({
    action: "open_compartment",
  });

  const decoded = JSON.parse(payload) as {
    action: string;
    message_id?: string;
    timestamp?: string;
  };

  assert.equal(decoded.action, "open_compartment");
  assert.equal(typeof decoded.message_id, "string");
  assert.equal(typeof decoded.timestamp, "string");
  assert.ok(decoded.timestamp);
});

test("prepareMQTTPayload preserves existing message_id and timestamp", () => {
  const payload = prepareMQTTPayload({
    message_id: "existing-id",
    action: "open_compartment",
    timestamp: "2026-01-01T00:00:00.000Z",
  });

  const decoded = JSON.parse(payload) as {
    action: string;
    message_id?: string;
    timestamp?: string;
  };

  assert.equal(decoded.action, "open_compartment");
  assert.equal(decoded.message_id, "existing-id");
  assert.equal(decoded.timestamp, "2026-01-01T00:00:00.000Z");
});

test("prepareMQTTPayload leaves string payloads unchanged", () => {
  const payload = prepareMQTTPayload("raw-payload");

  assert.equal(payload, "raw-payload");
});

test("MQTTService.publish passes qos and retain to underlying client.publish", async () => {
  const recorded: { opts: Record<string, unknown> }[] = [];
  const fakeClient = {
    connected: true,
    publish: (
      _topic: string,
      _payload: string,
      opts: Record<string, unknown>,
      cb: (err?: Error) => void,
    ) => {
      recorded.push({ opts });
      cb();
    },
  } as unknown as MqttClient;

  const originalGet = mqttClientManager.getClient.bind(mqttClientManager);
  mqttClientManager.getClient = () => fakeClient;

  try {
    const svc = new MQTTService();
    await svc.publish(
      "t/x",
      { state: "compartment_snapshot" },
      { qos: 1, retain: true },
    );
  } finally {
    mqttClientManager.getClient = originalGet;
  }

  assert.equal(recorded.length, 1);
  assert.equal(recorded[0].opts.qos, 1);
  assert.equal(recorded[0].opts.retain, true);
});
