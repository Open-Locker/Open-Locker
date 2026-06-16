import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  createEnvelope,
  serializeOutboundPayload,
} from '../../src/adapters/mqtt/outbound-envelope';

test('createEnvelope always adds message_id and timestamp', () => {
  const body = { type: 'heartbeat', status: 'online' };
  const envelope = createEnvelope(body, () => '2026-06-16T12:00:00.000Z');

  assert.equal(typeof envelope.message_id, 'string');
  assert.equal(envelope.timestamp, '2026-06-16T12:00:00.000Z');
});

test('createEnvelope preserves existing message_id and timestamp', () => {
  const envelope = createEnvelope(
    {
      message_id: 'fixed-id',
      timestamp: '2026-06-16T12:00:00.000Z',
      action: 'open_compartment',
    },
    () => 'should-not-be-used',
  );

  assert.equal(envelope.message_id, 'fixed-id');
  assert.equal(envelope.timestamp, '2026-06-16T12:00:00.000Z');
});

test('serializeOutboundPayload returns valid JSON', () => {
  const json = serializeOutboundPayload({ result: 'success' }, () => '2026-06-16T12:00:00.000Z');
  const parsed = JSON.parse(json) as Record<string, unknown>;
  assert.equal(typeof parsed.message_id, 'string');
  assert.equal(parsed.timestamp, '2026-06-16T12:00:00.000Z');
});
