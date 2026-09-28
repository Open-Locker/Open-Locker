import EventEmitter from 'node:events';
import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { InjectableSerialPort } from '../../src/adapters/rs485/injectable-serial-port';
import {
  RequestNotSentError,
  ResponseTimeoutError,
  SerialPortTransactionTransport,
} from '../../src/adapters/rs485/serialport-transaction.transport';
import { xorBcc } from '../../src/adapters/rs485/rs485-lock-board-codec';
import { HardwareTransportError } from '../../src/domain/errors';

const connection = {
  port: '/dev/test',
  baudRate: 9600,
  dataBits: 8 as const,
  stopBits: 1 as const,
  parity: 'none' as const,
};

class FakeSerialPort extends EventEmitter implements InjectableSerialPort {
  isOpen = false;
  flushCalls = 0;
  readonly written: Buffer[] = [];

  open(callback: (error?: Error | null) => void): void {
    this.isOpen = true;
    callback();
  }

  close(callback: (error?: Error | null) => void): void {
    this.isOpen = false;
    callback();
  }

  write(data: Buffer, callback: (error?: Error | null) => void): void {
    this.written.push(data);
    callback();
  }

  drain(callback: (error?: Error | null) => void): void {
    callback();
  }

  flush(callback: (error?: Error | null) => void): void {
    this.flushCalls++;
    callback();
  }

  push(chunk: Buffer): void {
    this.emit('data', chunk);
  }
}

function queryResponse(): Buffer {
  const body = [0x80, 0x01, 0x00, 0x33];
  return Buffer.from([...body, xorBcc(body)]);
}

function createTransport(port: FakeSerialPort): SerialPortTransactionTransport {
  return new SerialPortTransactionTransport(connection, {}, () => port);
}

const request = Buffer.from([0x80, 0x01, 0x00, 0x33, 0xb2]);

async function sleep(ms: number): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

/** transact() awaits RX flush before attaching data listeners. */
async function waitForTransactReady(): Promise<void> {
  await sleep(1);
}

test('transport completes a frame delivered in one chunk after inter-byte quiet', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();

  const pending = transport.transact(request, 200);
  await waitForTransactReady();
  port.push(queryResponse());
  await sleep(8);

  assert.deepEqual([...(await pending)], [...queryResponse()]);
});

test('transport assembles a frame split across chunks', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();

  const response = queryResponse();
  const pending = transport.transact(request, 200);
  await waitForTransactReady();
  port.push(response.subarray(0, 3));
  port.push(response.subarray(3));
  await sleep(8);

  assert.deepEqual([...(await pending)], [...response]);
});

test('transport classifies EACCES on serial open as non-reconnectable', async () => {
  class PermissionDeniedPort extends FakeSerialPort {
    override open(callback: (error?: Error | null) => void): void {
      callback(Object.assign(new Error('permission denied'), { code: 'EACCES' }));
    }
  }

  const transport = createTransport(new PermissionDeniedPort());

  await assert.rejects(
    () => transport.open(),
    (error: unknown) => {
      assert.ok(error instanceof HardwareTransportError);
      assert.equal(error.reconnectable, false);
      assert.match(error.message, /permission denied/);
      return true;
    },
  );
});

test('transport times out before the first byte and flushes RX before rejecting', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();

  await assert.rejects(() => transport.transact(request, 15), HardwareTransportError);
  assert.ok(port.flushCalls >= 1);
});

test('transport times out while noisy data keeps arriving', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();

  const pending = transport.transact(request, 30);
  await waitForTransactReady();
  const timer = setInterval(() => port.push(Buffer.from([0xaa])), 2);
  await assert.rejects(pending, /timed out/);
  clearInterval(timer);
});

test('transport flushes RX before rejecting on timeout', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();

  const flushCallsBefore = port.flushCalls;
  const pending = transport.transact(request, 25);
  await waitForTransactReady();
  const timer = setInterval(() => port.push(Buffer.from([0xaa])), 2);
  await assert.rejects(pending, HardwareTransportError);
  clearInterval(timer);
  assert.ok(
    port.flushCalls > flushCallsBefore + 1,
    'expected flush at transaction start and again before rejection',
  );
});

test('late bytes after a timeout are flushed away before the next transaction', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();

  const firstPending = transport.transact(request, 15);
  await waitForTransactReady();
  await assert.rejects(firstPending, HardwareTransportError);
  port.push(Buffer.from([0xde, 0xad]));

  let respondOnWrite = false;
  const baseWrite = port.write.bind(port);
  port.write = (data, callback) => {
    baseWrite(data, callback);
    if (respondOnWrite) {
      setImmediate(() => port.push(queryResponse()));
    }
  };

  respondOnWrite = true;
  const pending = transport.transact(request, 200);
  await waitForTransactReady();
  await sleep(8);

  assert.deepEqual([...(await pending)], [...queryResponse()]);
  assert.ok(port.flushCalls >= 2);
});

test('a response split into USB bursts 15 ms apart is still one frame', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();

  const response = queryResponse();
  const pending = transport.transact(request, 500);
  await waitForTransactReady();
  port.push(response.subarray(0, 2));
  await sleep(15);
  port.push(response.subarray(2));

  assert.deepEqual([...(await pending)], [...response]);
});

test('a timeout reports how many bytes arrived', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();

  await assert.rejects(
    () => transport.transact(request, 15),
    (error: unknown) => error instanceof ResponseTimeoutError && error.receivedBytes === 0,
  );
});

test('a closed port fails before anything is written', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);

  await assert.rejects(() => transport.transact(request, 50), RequestNotSentError);
  assert.deepEqual(port.written, []);
});

test('a serial error reported between transactions fails the next one before writing', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();
  port.emit('error', new Error('device disconnected'));

  await assert.rejects(() => transport.transact(request, 50), RequestNotSentError);
  assert.deepEqual(port.written, []);
});

test('a write error after the request started is not reported as not sent', async () => {
  const port = new FakeSerialPort();
  port.write = (data, callback) => {
    port.written.push(data);
    callback(new Error('write failed'));
  };
  const transport = createTransport(port);
  await transport.open();

  await assert.rejects(
    () => transport.transact(request, 200),
    (error: unknown) =>
      error instanceof HardwareTransportError && !(error instanceof RequestNotSentError),
  );
});

test('a frame the validator rejects fails the transaction', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();

  const pending = transport.transact(request, 200, () => {
    throw new Error('invalid response BCC');
  });
  await waitForTransactReady();
  port.push(queryResponse());

  await assert.rejects(pending, /invalid response BCC/);
});

test('a late response after a timeout is discarded, not read as the next answer', async () => {
  const port = new FakeSerialPort();
  const transport = createTransport(port);
  await transport.open();

  const lateResponse = Buffer.from([0x8a, 0x01, 0x01, 0x00, 0x8a]);
  const first = transport.transact(request, 15);
  await waitForTransactReady();
  setTimeout(() => port.push(lateResponse), 20);
  await assert.rejects(first, ResponseTimeoutError);

  const baseWrite = port.write.bind(port);
  port.write = (data, callback) => {
    baseWrite(data, callback);
    setImmediate(() => port.push(queryResponse()));
  };

  assert.deepEqual([...(await transport.transact(request, 200))], [...queryResponse()]);
});
