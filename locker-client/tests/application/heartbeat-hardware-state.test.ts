import assert from 'node:assert/strict';
import { test } from 'node:test';
import { HeartbeatUseCase } from '../../src/application/state-publishing';
import type { ConnectionState } from '../../src/ports/locker-bus.port';
import type { CommandResponseBody, OutboundMqttPort } from '../../src/ports/mqtt.port';
import { assertMatchesSchema } from '../contract/jsonSchema';

function createCapturingOutbound(): {
  outbound: OutboundMqttPort;
  published: Record<string, unknown>[];
} {
  const published: Record<string, unknown>[] = [];

  return {
    published,
    outbound: {
      async publishJson(_topic: string, body: Record<string, unknown>): Promise<void> {
        published.push(body);
      },
      async publishCommandResponse(_body: CommandResponseBody): Promise<void> {},
    },
  };
}

function busReporting(state: ConnectionState) {
  return { getConnectionState: () => state };
}

test('a reachable bus is reported as connected on the heartbeat', async () => {
  const { outbound, published } = createCapturingOutbound();
  const heartbeat = new HeartbeatUseCase(
    outbound,
    'locker/test/state/heartbeat',
    60_000,
    undefined,
    busReporting('connected'),
  );

  heartbeat.start();
  heartbeat.stop();
  await Promise.resolve();

  assert.equal(published[0]?.modbus_connected, true);
});

test('an unreachable bus is reported while the device keeps heartbeating', async () => {
  const { outbound, published } = createCapturingOutbound();
  const heartbeat = new HeartbeatUseCase(
    outbound,
    'locker/test/state/heartbeat',
    60_000,
    undefined,
    busReporting('disconnected'),
  );

  heartbeat.start();
  heartbeat.stop();
  await Promise.resolve();

  // The point of the signal: still alive on MQTT, but blind to its hardware.
  assert.equal(published[0]?.modbus_connected, false);
  assert.equal(typeof published[0]?.uptime_seconds, 'number');
});

test('no hardware source means the field is omitted, not guessed', async () => {
  const { outbound, published } = createCapturingOutbound();
  const heartbeat = new HeartbeatUseCase(outbound, 'locker/test/state/heartbeat', 60_000);

  heartbeat.start();
  heartbeat.stop();
  await Promise.resolve();

  assert.equal('modbus_connected' in (published[0] ?? {}), false);
});

test('a heartbeat carrying hardware state still matches the AsyncAPI schema', () => {
  assertMatchesSchema('payloads/state-heartbeat.json', {
    message_id: '11111111-1111-1111-1111-111111111111',
    timestamp: '2026-07-30T10:00:00Z',
    uptime_seconds: 120,
    modbus_connected: false,
  });
});
