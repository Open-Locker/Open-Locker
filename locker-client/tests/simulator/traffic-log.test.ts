import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createConsoleTrafficLogger } from '../../src/adapters/simulator/traffic-log';

function capture() {
  const lines: string[] = [];

  return { lines, logger: createConsoleTrafficLogger('main', (line) => lines.push(line)) };
}

test('snapshots list every door, marked retained', () => {
  const { lines, logger } = capture();

  logger.outbound(
    'locker/uuid-1/state/compartments',
    JSON.stringify({
      compartments: [
        { compartment_number: 1, door_state: 'open' },
        { compartment_number: 2, door_state: 'closed' },
      ],
    }),
    { retain: true },
  );

  assert.equal(lines[0], '[main] → state/compartments (retained)  #1:open #2:closed');
});

test('heartbeats show uptime', () => {
  const { lines, logger } = capture();

  logger.outbound('locker/uuid-1/state/heartbeat', JSON.stringify({ uptime_seconds: 42 }));

  assert.equal(lines[0], '[main] → state/heartbeat  uptime=42s');
});

test('command responses show action, result and a short transaction id', () => {
  const { lines, logger } = capture();

  logger.outbound(
    'locker/uuid-1/response',
    JSON.stringify({
      action: 'open_compartment',
      result: 'success',
      transaction_id: 'abcdef12-3456-7890-abcd-ef1234567890',
    }),
  );

  assert.equal(lines[0], '[main] → response  open_compartment success tx=abcdef12');
});

test('error responses include the error code', () => {
  const { lines, logger } = capture();

  logger.outbound(
    'locker/uuid-1/response',
    JSON.stringify({
      action: 'open_compartment',
      result: 'error',
      error_code: 'INVALID_CONFIG',
      transaction_id: 'abcdef12-3456',
    }),
  );

  assert.equal(lines[0], '[main] → response  open_compartment error INVALID_CONFIG tx=abcdef12');
});

test('inbound commands show the target compartment', () => {
  const { lines, logger } = capture();

  logger.inbound(
    'locker/uuid-1/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'abcdef12-3456',
      data: { compartment_number: 3 },
    }),
  );

  assert.equal(lines[0], '[main] ← command  open_compartment #3 tx=abcdef12');
});

test('unparseable inbound payloads are reported, not thrown', () => {
  const { lines, logger } = capture();

  logger.inbound('locker/uuid-1/command', '{not json');

  assert.equal(lines[0], '[main] ← command  <unparseable>');
});

test('the locker uuid is stripped so lines stay readable', () => {
  const { lines, logger } = capture();

  logger.outbound(
    'locker/019e5a20-8a02-718d-9146-a8a656edabbd/state/heartbeat',
    JSON.stringify({ uptime_seconds: 1 }),
  );

  assert.ok(!lines[0]!.includes('019e5a20'), lines[0]);
});
