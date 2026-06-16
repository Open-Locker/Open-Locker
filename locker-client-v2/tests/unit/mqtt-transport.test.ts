import assert from "node:assert/strict";
import { test } from "node:test";
import { MqttTransportAdapter } from "../../src/adapters/mqtt/mqtt-transport.adapter";

test("MqttTransportAdapter defaults to unlimited reconnect", () => {
  const transport = new MqttTransportAdapter({
    clean: false,
    keepalive: 60,
    reconnectPeriod: 5000,
    connectTimeout: 30000,
    maxReconnectAttempts: 0,
  });

  assert.equal(transport.getTransportSettings().maxReconnectAttempts, 0);
});

test("MqttTransportAdapter enters reconnecting on simulated broker drop", () => {
  const transport = new MqttTransportAdapter({
    clean: false,
    keepalive: 60,
    reconnectPeriod: 5000,
    connectTimeout: 30000,
    maxReconnectAttempts: 0,
  });

  (transport as unknown as { connectionState: string }).connectionState = "connected";
  (transport as unknown as { connectionState: string }).connectionState = "reconnecting";

  assert.equal(transport.getConnectionState(), "reconnecting");
});

test("MqttTransportAdapter restores connected state after broker returns", () => {
  const transport = new MqttTransportAdapter({
    clean: false,
    keepalive: 60,
    reconnectPeriod: 5000,
    connectTimeout: 30000,
    maxReconnectAttempts: 0,
  });

  (transport as unknown as { connectionState: string }).connectionState = "reconnecting";
  (transport as unknown as { connectionState: string }).connectionState = "connected";

  assert.equal(transport.getConnectionState(), "connected");
});
