import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Rs485LockBoardDriver } from '../../src/adapters/rs485/rs485-lock-board.driver';
import { HardwareTransportError } from '../../src/domain/errors';
import {
  VERIFIED_8_CHANNEL_QUERY_ALL_RESPONSE,
  xorBcc,
} from '../../src/adapters/rs485/rs485-lock-board-codec';
import type { Rs485TransactionTransport } from '../../src/adapters/rs485/serialport-transaction.transport';

class RecordingTransport implements Rs485TransactionTransport {
  requests: Array<{ bytes: number[]; timeoutMs: number }> = [];
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
  async transact(request: Uint8Array, timeoutMs: number): Promise<Buffer> {
    this.requests.push({ bytes: [...request], timeoutMs });
    return this.response;
  }
}

test('driver generates exact unlock transaction and acknowledges success', async () => {
  const transport = new RecordingTransport();
  const responseBody = [0x8a, 3, 12, 0x00];
  transport.response = Buffer.from([...responseBody, xorBcc(responseBody)]);
  const driver = new Rs485LockBoardDriver(transport, 'door_closing', 1750);

  await driver.unlock(3, 11);
  assert.deepEqual(transport.requests, [
    {
      bytes: [0x8a, 3, 12, 0x11, xorBcc([0x8a, 3, 12, 0x11])],
      timeoutMs: 1750,
    },
  ]);
});

test('driver decodes the verified 8-channel query-all response', async () => {
  const transport = new RecordingTransport();
  transport.response = Buffer.from(VERIFIED_8_CHANNEL_QUERY_ALL_RESPONSE);
  const driver = new Rs485LockBoardDriver(transport, 'door_closing');

  const states = await driver.queryAll(1);
  assert.equal(states.length, 24);
  assert.deepEqual(transport.requests[0]?.bytes, [0x80, 1, 0, 0x33, 0xb2]);
});

test('driver validates wire channel encodability', async () => {
  const driver = new Rs485LockBoardDriver(new RecordingTransport(), 'door_closing');
  await assert.rejects(() => driver.unlock(1, 255), /between 0 and 254/);
});

test('driver acknowledges unlock with either supported status byte', async () => {
  for (const status of [0x00, 0x11]) {
    const transport = new RecordingTransport();
    const responseBody = [0x8a, 1, 1, status];
    transport.response = Buffer.from([...responseBody, xorBcc(responseBody)]);
    const driver = new Rs485LockBoardDriver(transport, 'door_opening');
    await driver.unlock(1, 0);
  }
});

test('driver maps malformed unlock replies to reconnectable transport errors', async () => {
  const transport = new RecordingTransport();
  transport.response = Buffer.from([0x8a, 1, 1, 0x00, 0x00]);
  const driver = new Rs485LockBoardDriver(transport, 'door_closing');
  await assert.rejects(
    () => driver.unlock(1, 0),
    (error: unknown) => {
      assert.ok(error instanceof HardwareTransportError);
      assert.match(error.message, /BCC/);
      assert.equal(error.reconnectable, true);
      return true;
    },
  );
});
