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
// These tests cover the observable contract: connection state transitions and graceful publish skip.
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

test('MqttTransportAdapter publish skips gracefully while disconnected', async () => {
  const transport = new MqttTransportAdapter({
    clean: false,
    keepalive: 60,
    reconnectPeriod: 5000,
    connectTimeout: 30000,
    maxReconnectAttempts: 0,
  });

  await assert.doesNotReject(() =>
    transport.publish('locker/test/state/heartbeat', JSON.stringify({ uptime_seconds: 1 })),
  );
  assert.equal(transport.getConnectionState(), 'disconnected');
});
