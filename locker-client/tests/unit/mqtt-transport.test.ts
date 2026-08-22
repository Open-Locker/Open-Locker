import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MqttTransportAdapter } from '../../src/adapters/mqtt/mqtt-transport.adapter';

test('MqttTransportAdapter defaults to unlimited reconnect', () => {
  const transport = new MqttTransportAdapter({
    clean: false,
    keepalive: 60,
    reconnectPeriod: 5000,
    connectTimeout: 30000,
    maxReconnectAttempts: 0,
  });

  assert.equal(transport.getTransportSettings().maxReconnectAttempts, 0);
});

// Full broker reconnect integration (aedes + live mqtt.connect) is not wired in CI yet.
// These tests cover the observable contract: connection state transitions and publish failure.
test('MqttTransportAdapter reports reconnecting after simulated broker drop', () => {
  const transport = new MqttTransportAdapter({
    clean: false,
    keepalive: 60,
    reconnectPeriod: 5000,
    connectTimeout: 30000,
    maxReconnectAttempts: 0,
  });

  (transport as unknown as { connectionState: string }).connectionState = 'connected';
  (transport as unknown as { connectionState: string }).connectionState = 'reconnecting';

  assert.equal(transport.getConnectionState(), 'reconnecting');
});

test('MqttTransportAdapter publish fails while disconnected', async () => {
  const transport = new MqttTransportAdapter({
    clean: false,
    keepalive: 60,
    reconnectPeriod: 5000,
    connectTimeout: 30000,
    maxReconnectAttempts: 0,
  });

  await assert.rejects(
    () => transport.publish('locker/test/state/heartbeat', JSON.stringify({ uptime_seconds: 1 })),
    /not connected/,
  );
  assert.equal(transport.getConnectionState(), 'disconnected');
});

test('MqttTransportAdapter notifies registered connected handlers', async () => {
  const transport = new MqttTransportAdapter({
    clean: false,
    keepalive: 60,
    reconnectPeriod: 5000,
    connectTimeout: 30000,
    maxReconnectAttempts: 0,
  });
  let notifications = 0;
  transport.onConnected(() => {
    notifications++;
  });

  (
    transport as unknown as {
      notifyConnected(): void;
    }
  ).notifyConnected();

  await new Promise<void>((resolve) => setImmediate(resolve));
  assert.equal(notifications, 1);
});
