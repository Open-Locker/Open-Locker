import assert from 'node:assert/strict';
import { test } from 'node:test';
import { calculateInterTransactionDelayMs } from '../../src/adapters/serial/serial-framing-timing';

test('calculates verified 5 ms inter-transaction delay for 9600 8N1', () => {
  assert.equal(
    calculateInterTransactionDelayMs({
      baudRate: 9600,
      dataBits: 8,
      stopBits: 1,
      parity: 'none',
    }),
    5,
  );
});
