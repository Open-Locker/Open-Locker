import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { test, type TestContext } from 'node:test';
import mqtt, { type MqttClient } from 'mqtt';
import { logger } from '../../src/infrastructure/logging';
import type { MqttTransportSettings } from '../../src/ports/mqtt.port';
import {
  MqttTransportAdapter,
  withVerifiedMqttTls,
} from '../../src/adapters/mqtt/mqtt-transport.adapter';

test('MQTTS always verifies the certificate and hostname', () => {
  assert.deepEqual(
    withVerifiedMqttTls('mqtts://mqtt.example.com:8883', {
      rejectUnauthorized: false,
    }),
    {
      rejectUnauthorized: true,
    },
  );
});

test('local plaintext MQTT keeps its connection options', () => {
  const options = { clientId: 'local-client' };

  assert.strictEqual(withVerifiedMqttTls('mqtt://localhost:1883', options), options);
});

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

class ControlledMqttClient extends EventEmitter {
  connected = false;
  readonly shutdowns: boolean[] = [];

  end(force: boolean, callback?: () => void): void {
    this.shutdowns.push(force);
    this.connected = false;
    this.emit('close');
    callback?.();
  }

  establishConnection(): void {
    this.connected = true;
    this.emit('connect');
  }
}

function setup(t: TestContext, settings: Partial<MqttTransportSettings> = {}) {
  t.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
  const client = new ControlledMqttClient();
  const connect = t.mock.method(mqtt, 'connect', () => client as unknown as MqttClient);
  const warnings = t.mock.method(logger, 'warn', () => logger);
  t.mock.method(logger, 'error', () => logger);
  const adapter = new MqttTransportAdapter({
    clean: false,
    keepalive: 60,
    reconnectPeriod: 5000,
    connectTimeout: 30000,
    maxReconnectAttempts: 0,
    ...settings,
  });
  return { adapter, client, connect, warnings };
}

function observeStartup(adapter: MqttTransportAdapter, options: Record<string, unknown> = {}) {
  const outcome: { value: 'pending' | 'connected' | 'failed'; error?: Error } = {
    value: 'pending',
  };
  const startup = adapter.connect('mqtt://localhost:1883', options).then(
    () => {
      outcome.value = 'connected';
    },
    (error: Error) => {
      outcome.value = 'failed';
      outcome.error = error;
    },
  );
  return { startup, outcome };
}

async function drainPromises(): Promise<void> {
  await new Promise<void>((resolve) => setImmediate(resolve));
}

test('a broker recovery updates state and notifies handlers registered after startup', async (t) => {
  const { adapter, client } = setup(t);
  const { startup } = observeStartup(adapter);
  client.establishConnection();
  await startup;
  let notifications = 0;
  adapter.onConnected(() => {
    notifications++;
  });

  client.connected = false;
  client.emit('offline');
  client.emit('close');
  client.emit('reconnect');
  assert.equal(adapter.getConnectionState(), 'reconnecting');
  client.establishConnection();
  await drainPromises();
  assert.equal(adapter.getConnectionState(), 'connected');
  assert.equal(notifications, 1);
});

test('initial errors report diagnostics and allow startup to recover', async (t) => {
  const { adapter, client, warnings, connect } = setup(t);
  const { startup, outcome } = observeStartup(adapter);
  client.emit('error', new Error('ECONNREFUSED'));
  client.emit('close');
  await drainPromises();
  assert.equal(outcome.value, 'pending');
  assert.deepEqual(client.shutdowns, []);
  assert.equal(adapter.getConnectionState(), 'reconnecting');
  assert.equal(warnings.mock.callCount(), 1);
  const warningArguments: unknown[] = warnings.mock.calls[0].arguments;
  assert.deepEqual(warningArguments[1], {
    brokerUrl: 'mqtt://localhost:1883',
    error: 'ECONNREFUSED',
    reconnectAttempts: 0,
  });
  client.emit('reconnect');
  const concurrentStartup = adapter.connect('mqtt://localhost:1883');
  client.establishConnection();
  await Promise.all([startup, concurrentStartup]);
  assert.equal(outcome.value, 'connected');
  assert.equal(connect.mock.callCount(), 1);
});

test('startup watchdog diagnoses a silent broker and permits a late connection', async (t) => {
  const { adapter, client, warnings } = setup(t);
  const { startup, outcome } = observeStartup(adapter);
  t.mock.timers.tick(30000);
  await drainPromises();
  assert.equal(outcome.value, 'pending');
  assert.deepEqual(client.shutdowns, []);
  assert.equal(warnings.mock.callCount(), 1);
  assert.match(String(warnings.mock.calls[0].arguments[0]), /waiting.*MQTT/i);
  let notifications = 0;
  adapter.onConnected(() => {
    notifications++;
  });
  client.establishConnection();
  await startup;
  assert.equal(adapter.getConnectionState(), 'connected');
  assert.equal(notifications, 1);
});

test('successful startup cancels the watchdog and repeated errors are rate limited', async (t) => {
  const { adapter, client, warnings } = setup(t);
  const { startup } = observeStartup(adapter);
  client.emit('error', new Error('ECONNREFUSED'));
  client.emit('error', new Error('ECONNREFUSED'));
  assert.equal(warnings.mock.callCount(), 1);
  client.establishConnection();
  await startup;
  t.mock.timers.tick(30000);
  assert.equal(warnings.mock.callCount(), 1);
  client.emit('error', new Error('broker unavailable'));
  assert.equal(warnings.mock.callCount(), 2);
});

for (const event of ['error', 'timeout', 'close'] as const) {
  test(`disabled reconnect rejects startup on ${event} and ignores late events`, async (t) => {
    const { adapter, client } = setup(t, { reconnectPeriod: 0 });
    const { startup, outcome } = observeStartup(adapter);
    if (event === 'timeout') {
      t.mock.timers.tick(30000);
    } else if (event === 'error') {
      client.emit('error', new Error('ECONNREFUSED'));
    } else {
      client.emit('close');
    }
    await drainPromises();
    assert.equal(outcome.value, 'failed');
    await startup;
    assert.deepEqual(client.shutdowns, [true]);
    let notifications = 0;
    adapter.onConnected(() => {
      notifications++;
    });
    client.emit('offline');
    client.emit('reconnect');
    client.establishConnection();
    client.emit('close');
    assert.equal(adapter.getConnectionState(), 'disconnected');
    assert.equal(notifications, 0);
  });
}

test('a configured retry cap rejects pending startup and force-closes once', async (t) => {
  const { adapter, client } = setup(t, { maxReconnectAttempts: 2 });
  const { startup, outcome } = observeStartup(adapter);
  client.emit('reconnect');
  assert.deepEqual(client.shutdowns, []);
  client.emit('reconnect');
  await drainPromises();
  assert.equal(outcome.value, 'failed');
  await startup;
  client.emit('reconnect');
  client.emit('offline');
  assert.deepEqual(client.shutdowns, [true]);
  assert.equal(adapter.getConnectionState(), 'disconnected');
});

test('disconnect cancels pending startup and stale client events cannot revive a new connection', async (t) => {
  const { adapter, client, warnings } = setup(t);
  const { startup, outcome } = observeStartup(adapter);
  await adapter.disconnect();
  await drainPromises();
  assert.equal(outcome.value, 'failed');
  await startup;
  const nextClient = new ControlledMqttClient();
  t.mock.method(mqtt, 'connect', () => nextClient as unknown as MqttClient);
  const nextStartup = adapter.connect('mqtt://localhost:1883');
  client.establishConnection();
  client.emit('offline');
  client.emit('close');
  assert.equal(adapter.getConnectionState(), 'connecting');
  nextClient.establishConnection();
  await nextStartup;
  client.emit('close');
  t.mock.timers.tick(30000);
  assert.equal(adapter.getConnectionState(), 'connected');
  assert.equal(warnings.mock.callCount(), 0);
});

test('connect overrides determine the watchdog and disabled retry behavior', async (t) => {
  const { adapter, client } = setup(t);
  const { startup, outcome } = observeStartup(adapter, {
    connectTimeout: 50,
    reconnectPeriod: 0,
  });
  t.mock.timers.tick(49);
  await drainPromises();
  assert.equal(outcome.value, 'pending');
  t.mock.timers.tick(1);
  await startup;
  assert.equal(outcome.value, 'failed');
  assert.match(outcome.error!.message, /50ms/);
  assert.deepEqual(client.shutdowns, [true]);
  assert.equal(adapter.getConnectionState(), 'disconnected');
});

test('a successful recovery resets the configured retry cap', async (t) => {
  const { adapter, client } = setup(t, { maxReconnectAttempts: 2 });
  const { startup } = observeStartup(adapter);
  client.emit('reconnect');
  client.establishConnection();
  await startup;
  client.connected = false;
  client.emit('close');
  client.emit('reconnect');
  assert.deepEqual(client.shutdowns, []);
  client.establishConnection();
  assert.equal(adapter.getConnectionState(), 'connected');
  client.connected = false;
  client.emit('close');
  client.emit('reconnect');
  client.emit('reconnect');
  assert.deepEqual(client.shutdowns, [true]);
  assert.equal(adapter.getConnectionState(), 'disconnected');
});
