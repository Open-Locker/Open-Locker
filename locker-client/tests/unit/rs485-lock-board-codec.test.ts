import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  decodeQueryAllResponse,
  decodeUnlockAck,
  encodeQueryAllRequest,
  encodeUnlockRequest,
  VERIFIED_8_CHANNEL_QUERY_ALL_RESPONSE,
  xorBcc,
} from '../../src/adapters/rs485/rs485-lock-board-codec';

test('encodes zero-based Open-Locker channel as one-based wire channel', () => {
  assert.deepEqual([...encodeUnlockRequest(2, 0)], [0x8a, 0x02, 0x01, 0x11, 0x98]);
  assert.deepEqual([...encodeQueryAllRequest(2)], [0x80, 0x02, 0x00, 0x33, 0xb1]);
});

test('accepts the verified 8-channel board query-all capture', () => {
  const states = decodeQueryAllResponse(VERIFIED_8_CHANNEL_QUERY_ALL_RESPONSE, 1, 'door_closing');

  assert.equal(states.length, 24);
  assert.deepEqual(
    states.slice(0, 8),
    Array.from({ length: 8 }, () => 'open'),
  );
});

test('decodes every status byte in the frame without trimming to a declared board size', () => {
  const body = [0x80, 0x02, 0b0101, 0b0000_0011, 0x33];
  const states = decodeQueryAllResponse(
    Uint8Array.from([...body, xorBcc(body)]),
    2,
    'door_closing',
  );

  assert.equal(states.length, 16);
  assert.deepEqual(states.slice(0, 4), ['closed', 'closed', 'open', 'open']);
  assert.deepEqual(states.slice(8, 12), ['closed', 'open', 'closed', 'open']);
});

test('unlock ack accepts supported status bytes regardless of door feedback polarity', () => {
  for (const status of [0x00, 0x11]) {
    const unlockBody = [0x8a, 0x01, 0x01, status];
    assert.doesNotThrow(() =>
      decodeUnlockAck(Uint8Array.from([...unlockBody, xorBcc(unlockBody)]), 1, 0),
    );
  }
});

test('query-all still inverts door state for door_opening wiring', () => {
  const queryBody = [0x80, 0x01, 0x01, 0x33];
  assert.deepEqual(
    decodeQueryAllResponse(
      Uint8Array.from([...queryBody, xorBcc(queryBody)]),
      1,
      'door_opening',
    ).slice(0, 2),
    ['open', 'closed'],
  );
});

test('rejects malformed BCC, address, and truncated frames', () => {
  assert.throws(() => decodeUnlockAck(Uint8Array.from([0x8a, 1, 1, 0, 0]), 1, 0), /BCC/);
  const body = [0x8a, 2, 1, 0];
  assert.throws(
    () => decodeUnlockAck(Uint8Array.from([...body, xorBcc(body)]), 1, 0),
    /does not match/,
  );
  const badStatus = [0x8a, 1, 1, 0x22];
  assert.throws(
    () => decodeUnlockAck(Uint8Array.from([...badStatus, xorBcc(badStatus)]), 1, 0),
    /unsupported unlock response status/,
  );
  assert.throws(() => decodeQueryAllResponse(Uint8Array.from([0x80]), 1, 'door_closing'), /length/);
});
