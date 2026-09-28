import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Rs485LockBoardDriver } from '../../src/adapters/rs485/rs485-lock-board.driver';
import {
  BoardNotRespondingError,
  HardwareTransportError,
  UnlockNotSentError,
  UnlockOutcomeUnknownError,
} from '../../src/domain/errors';
import {
  VERIFIED_8_CHANNEL_QUERY_ALL_RESPONSE,
  xorBcc,
} from '../../src/adapters/rs485/rs485-lock-board-codec';
import {
  RequestNotSentError,
  ResponseTimeoutError,
  type ResponseValidator,
  type Rs485TransactionTransport,
} from '../../src/adapters/rs485/serialport-transaction.transport';

/** Applies the validator the way the real transport does, so decode failures surface. */
class RecordingTransport implements Rs485TransactionTransport {
  requests: Array<{ bytes: number[]; timeoutMs: number }> = [];
  openState = false;
  response: Buffer = Buffer.alloc(0);
  failure: Error | null = null;

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
    timeoutMs: number,
    validate?: ResponseValidator,
  ): Promise<Buffer> {
    this.requests.push({ bytes: [...request], timeoutMs });
    if (this.failure) {
      throw this.failure;
    }
    try {
      validate?.(this.response);
    } catch (error) {
      throw new HardwareTransportError(
        error instanceof Error ? error.message : String(error),
        true,
      );
    }
    return this.response;
  }
}

function unlockResponse(board: number, wireChannel: number, status: number): Buffer {
  const body = [0x8a, board, wireChannel, status];
  return Buffer.from([...body, xorBcc(body)]);
}

test('driver generates the exact unlock transaction', async () => {
  const transport = new RecordingTransport();
  transport.response = unlockResponse(3, 12, 0x00);
  const driver = new Rs485LockBoardDriver(transport, 'door_closing', 1750);

  await driver.unlock(3, 11);
  assert.deepEqual(transport.requests, [
    {
      bytes: [0x8a, 3, 12, 0x11, xorBcc([0x8a, 3, 12, 0x11])],
      timeoutMs: 1750,
    },
  ]);
});

test('driver reports the unlock feedback through the configured polarity', async () => {
  const cases = [
    { feedback: 'door_closing', status: 0x00, expected: 'open' },
    { feedback: 'door_closing', status: 0x11, expected: 'closed' },
    { feedback: 'door_opening', status: 0x11, expected: 'open' },
    { feedback: 'door_opening', status: 0x00, expected: 'closed' },
  ] as const;
  for (const { feedback, status, expected } of cases) {
    const transport = new RecordingTransport();
    transport.response = unlockResponse(1, 1, status);
    const driver = new Rs485LockBoardDriver(transport, feedback);

    assert.equal(await driver.unlock(1, 0), expected, `${feedback} 0x${status.toString(16)}`);
  }
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

test('a malformed unlock reply means the unlock outcome is unknown', async () => {
  const transport = new RecordingTransport();
  transport.response = Buffer.from([0x8a, 1, 1, 0x00, 0x00]);
  const driver = new Rs485LockBoardDriver(transport, 'door_closing');
  await assert.rejects(
    () => driver.unlock(1, 0),
    (error: unknown) => {
      assert.ok(error instanceof UnlockOutcomeUnknownError);
      assert.match(error.message, /BCC/);
      assert.equal(error.reconnectable, true);
      return true;
    },
  );
});

test('a reply for another channel means the unlock outcome is unknown', async () => {
  const transport = new RecordingTransport();
  transport.response = unlockResponse(1, 2, 0x00);
  const driver = new Rs485LockBoardDriver(transport, 'door_closing');

  await assert.rejects(() => driver.unlock(1, 0), UnlockOutcomeUnknownError);
});

test('a lost unlock reply means the unlock outcome is unknown', async () => {
  const transport = new RecordingTransport();
  transport.failure = new ResponseTimeoutError(1500, 0);
  const driver = new Rs485LockBoardDriver(transport, 'door_closing');

  await assert.rejects(() => driver.unlock(1, 0), UnlockOutcomeUnknownError);
});

test('an unlock that never reached the wire is reported as not sent', async () => {
  const transport = new RecordingTransport();
  transport.failure = new RequestNotSentError('RS485 port is not open');
  const driver = new Rs485LockBoardDriver(transport, 'door_closing');

  await assert.rejects(
    () => driver.unlock(1, 0),
    (error: unknown) => error instanceof UnlockNotSentError && error.reconnectable,
  );
});

test('a board that stays silent on query-all is reported as not responding', async () => {
  const transport = new RecordingTransport();
  transport.failure = new ResponseTimeoutError(1500, 0);
  const driver = new Rs485LockBoardDriver(transport, 'door_closing');

  await assert.rejects(() => driver.queryAll(4), BoardNotRespondingError);
});

test('a partial query-all reply is a transport failure, not a silent board', async () => {
  const transport = new RecordingTransport();
  transport.failure = new ResponseTimeoutError(1500, 3);
  const driver = new Rs485LockBoardDriver(transport, 'door_closing');

  await assert.rejects(
    () => driver.queryAll(4),
    (error: unknown) =>
      error instanceof ResponseTimeoutError && !(error instanceof BoardNotRespondingError),
  );
});
