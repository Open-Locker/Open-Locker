import assert from 'node:assert/strict';
import { test } from 'node:test';
import { SerialBusConnection } from '../../src/adapters/serial/serial-bus-connection';
import { isReconnectableHardwareError } from '../../src/domain/errors';

class TrackingDriver {
  connectAttempts = 0;
  disconnectCalls = 0;
  private open = false;
  private readonly failWith: Error;

  constructor(failWith: Error) {
    this.failWith = failWith;
  }

  async connect(): Promise<void> {
    this.connectAttempts++;
    throw this.failWith;
  }

  async disconnect(): Promise<void> {
    this.disconnectCalls++;
    this.open = false;
  }

  isOpen(): boolean {
    return this.open;
  }
}

test('SerialBusConnection does not retry non-reconnectable connect failures', async () => {
  const driver = new TrackingDriver(
    Object.assign(new Error('permission denied'), { code: 'EACCES' }),
  );
  const connection = new SerialBusConnection(
    driver,
    { maxAttempts: 5, delayMs: 1 },
    isReconnectableHardwareError,
  );

  assert.equal(await connection.dial(), false);
  assert.equal(driver.connectAttempts, 1);
  assert.equal(
    connection.getConnectionState(),
    'disconnected',
    'non-reconnectable dial failure must not leave the bus connecting',
  );
});

test('SerialBusConnection retries reconnectable connect failures until the cycle is spent', async () => {
  const driver = new TrackingDriver(Object.assign(new Error('device missing'), { code: 'ENOENT' }));
  const connection = new SerialBusConnection(
    driver,
    { maxAttempts: 3, delayMs: 1 },
    isReconnectableHardwareError,
  );

  assert.equal(await connection.dial(), false);
  assert.equal(driver.connectAttempts, 3);
  assert.equal(connection.getConnectionState(), 'unreachable');
});

test('SerialBusConnection disconnects and reconnects before retrying an operation', async () => {
  let open = false;
  let operationAttempts = 0;
  const driver = {
    async connect() {
      open = true;
    },
    async disconnect() {
      open = false;
    },
    isOpen() {
      return open;
    },
  };
  const connection = new SerialBusConnection(
    driver,
    { maxAttempts: 3, delayMs: 1 },
    isReconnectableHardwareError,
  );
  await connection.dial();

  await connection.runWithReconnectRetry(async () => {
    operationAttempts++;
    if (operationAttempts === 1) {
      throw Object.assign(new Error('input/output error'), { code: 'EIO' });
    }
    return 'ok';
  });

  assert.equal(operationAttempts, 2);
  assert.equal(open, true);
});
