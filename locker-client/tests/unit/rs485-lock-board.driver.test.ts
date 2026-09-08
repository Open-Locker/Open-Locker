import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Rs485LockBoardDriver } from '../../src/adapters/rs485/rs485-lock-board.driver';
import { xorBcc } from '../../src/adapters/rs485/rs485-lock-board-codec';
import type { Rs485TransactionTransport } from '../../src/adapters/rs485/serialport-transaction.transport';

class RecordingTransport implements Rs485TransactionTransport {
  requests: Array<{ bytes: number[]; expectedLength: number; timeoutMs: number }> = [];
  openState = false;
  response = Buffer.alloc(0);

  async open(): Promise<void> {
    this.openState = true;
  }
  async close(): Promise<void> {
    this.openState = false;
  }
  isOpen(): boolean {
    return this.openState;
  }
  async transact(
    request: Uint8Array,
    expectedResponseLength: number,
    timeoutMs: number,
  ): Promise<Buffer> {
    this.requests.push({ bytes: [...request], expectedLength: expectedResponseLength, timeoutMs });
    return this.response;
  }
}

test('driver generates exact unlock transaction and decodes early success', async () => {
  const transport = new RecordingTransport();
  const responseBody = [0x8a, 3, 12, 0x00];
  transport.response = Buffer.from([...responseBody, xorBcc(responseBody)]);
  const driver = new Rs485LockBoardDriver(transport, 12, 'door_closing', 1750);

  assert.equal(await driver.unlock(3, 11), 'opened');
  assert.deepEqual(transport.requests, [
    {
      bytes: [0x8a, 3, 12, 0x11, xorBcc([0x8a, 3, 12, 0x11])],
      expectedLength: 5,
      timeoutMs: 1750,
    },
  ]);
});

test('driver queries all status bytes in one transaction', async () => {
  const transport = new RecordingTransport();
  const responseBody = [0x80, 1, 0x00, 0xff, 0x33];
  transport.response = Buffer.from([...responseBody, xorBcc(responseBody)]);
  const driver = new Rs485LockBoardDriver(transport, 12, 'door_closing');

  const states = await driver.queryAll(1);
  assert.equal(states.length, 12);
  assert.equal(transport.requests[0]?.expectedLength, 6);
  assert.deepEqual(transport.requests[0]?.bytes, [0x80, 1, 0, 0x33, 0xb2]);
});

test('driver validates channel against selected board variant', async () => {
  const driver = new Rs485LockBoardDriver(new RecordingTransport(), 8, 'door_closing');
  await assert.rejects(() => driver.unlock(1, 8), /between 0 and 7/);
});
