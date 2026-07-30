import assert from 'node:assert/strict';
import { test } from 'node:test';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
import type { LoggerPort } from '../../src/ports/logging.port';

interface CapturedLog {
  level: 'warn' | 'error';
  message: string;
  meta?: Record<string, unknown>;
}

function createRecordingLogger(): { logger: LoggerPort; entries: CapturedLog[] } {
  const entries: CapturedLog[] = [];
  return {
    entries,
    logger: {
      warn: (message, meta) => entries.push({ level: 'warn', message, meta }),
      error: (message, meta) => entries.push({ level: 'error', message, meta }),
    },
  };
}

test('a command response dropped before the broker is reported with its ids', async () => {
  const { logger, entries } = createRecordingLogger();
  const outbound = new OutboundMqttAdapter(
    async () => false,
    'locker/test/response',
    () => '2026-07-30T10:00:00Z',
    undefined,
    logger,
  );

  await outbound.publishCommandResponse({
    action: 'open_compartment',
    result: 'error',
    transaction_id: 'txn-lost',
    error_code: 'DOOR_JAMMED',
    message: 'Door did not open',
  });

  const dropped = entries.find((entry) => entry.message.includes('dropped before reaching'));
  assert.ok(dropped, 'expected the dropped response to be reported');
  assert.equal(dropped.level, 'error');
  assert.equal(dropped.meta?.transactionId, 'txn-lost');
  assert.equal(dropped.meta?.action, 'open_compartment');
  assert.equal(dropped.meta?.topic, 'locker/test/response');
});

test('a delivered message is not reported as dropped', async () => {
  const { logger, entries } = createRecordingLogger();
  const outbound = new OutboundMqttAdapter(
    async () => true,
    'locker/test/response',
    () => '2026-07-30T10:00:00Z',
    undefined,
    logger,
  );

  await outbound.publishCommandResponse({
    action: 'open_compartment',
    result: 'success',
    transaction_id: 'txn-fine',
  });

  assert.equal(entries.length, 0);
});

test('an untraced topic still reports a dropped publish', async () => {
  const { logger, entries } = createRecordingLogger();
  const outbound = new OutboundMqttAdapter(
    async () => false,
    'locker/test/response',
    () => '2026-07-30T10:00:00Z',
    undefined,
    logger,
  );

  // Heartbeats bypass the tracing branch, so they exercise the other publish path.
  await outbound.publishJson('locker/test/state/heartbeat', { uptime_seconds: 12 }, { qos: 1 });

  assert.equal(entries.length, 1);
  assert.equal(entries[0]?.meta?.topic, 'locker/test/state/heartbeat');
});
