import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  decodeQueryAllResponse,
  decodeUnlockResponse,
  encodeQueryAllRequest,
  encodeUnlockRequest,
  xorBcc,
} from '../../src/adapters/rs485/rs485-lock-board-codec';

test('encodes zero-based Open-Locker channel as one-based wire channel', () => {
  assert.deepEqual([...encodeUnlockRequest(2, 0)], [0x8a, 0x02, 0x01, 0x11, 0x98]);
  assert.deepEqual([...encodeQueryAllRequest(2)], [0x80, 0x02, 0x00, 0x33, 0xb1]);
});

test('decodes highest channel group first and trims unused bits', () => {
  const body = [0x80, 0x02, 0b0101, 0b0000_0011, 0x33];
  const states = decodeQueryAllResponse(
    Uint8Array.from([...body, xorBcc(body)]),
    2,
    12,
    'door_closing',
  );

  assert.equal(states.length, 12);
  assert.deepEqual(states.slice(0, 4), ['closed', 'closed', 'open', 'open']);
  assert.deepEqual(states.slice(8), ['closed', 'open', 'closed', 'open']);
});

test('inverts query and unlock feedback for door_opening wiring', () => {
  const queryBody = [0x80, 0x01, 0x01, 0x33];
  assert.deepEqual(
    decodeQueryAllResponse(
      Uint8Array.from([...queryBody, xorBcc(queryBody)]),
      1,
      8,
      'door_opening',
    ).slice(0, 2),
    ['open', 'closed'],
  );

  const unlockBody = [0x8a, 0x01, 0x01, 0x11];
  assert.equal(
    decodeUnlockResponse(
      Uint8Array.from([...unlockBody, xorBcc(unlockBody)]),
      1,
      0,
      'door_opening',
    ),
    'opened',
  );
});

test('rejects malformed BCC, address, and response length', () => {
  assert.throws(
    () => decodeUnlockResponse(Uint8Array.from([0x8a, 1, 1, 0, 0]), 1, 0, 'door_closing'),
    /BCC/,
  );
  const body = [0x8a, 2, 1, 0];
  assert.throws(
    () => decodeUnlockResponse(Uint8Array.from([...body, xorBcc(body)]), 1, 0, 'door_closing'),
    /does not match/,
  );
  assert.throws(
    () => decodeQueryAllResponse(Uint8Array.from([0x80]), 1, 8, 'door_closing'),
    /length/,
  );
});
