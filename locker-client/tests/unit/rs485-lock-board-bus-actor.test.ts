import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Rs485LockBoardBusActor } from '../../src/adapters/rs485/rs485-lock-board-bus-actor';
import type { Rs485LockBoardDriverPort } from '../../src/adapters/rs485/rs485-lock-board.driver';
import { HardwareTransportError } from '../../src/domain/errors';

class ControlledDriver implements Rs485LockBoardDriverPort {
  open = false;
  active = 0;
  maximumActive = 0;
  unlockCalls: number[] = [];

  async connect(): Promise<void> {
    this.open = true;
  }
  async disconnect(): Promise<void> {
    this.open = false;
  }
  isOpen(): boolean {
    return this.open;
  }
  async unlock(_board: number, channel: number): Promise<void> {
    this.active++;
    this.maximumActive = Math.max(this.maximumActive, this.active);
    this.unlockCalls.push(channel);
    await new Promise((resolve) => setTimeout(resolve, 5));
    this.active--;
  }
  async queryAll(): Promise<Array<'open' | 'closed'>> {
    return ['closed', 'open', 'closed', 'open'];
  }
}

test('RS485 actor serializes transactions', async () => {
  const driver = new ControlledDriver();
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });
  await bus.connect();

  await Promise.all([
    bus.flashRelay({ compartmentNumber: 1, slaveId: 1, relayAddress: 0 }, 200),
    bus.flashRelay({ compartmentNumber: 2, slaveId: 1, relayAddress: 1 }, 200),
  ]);

  assert.equal(driver.maximumActive, 1);
  assert.deepEqual(driver.unlockCalls, [0, 1]);
});

test('RS485 opening connects before unlocking in the command operation', async () => {
  const driver = new ControlledDriver();
  const operations: string[] = [];
  driver.connect = async () => {
    operations.push('connect');
    driver.open = true;
  };
  driver.unlock = async () => {
    operations.push('unlock');
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });

  await bus.flashRelay({ compartmentNumber: 1, slaveId: 1, relayAddress: 0 }, 200);

  assert.deepEqual(operations, ['connect', 'unlock']);
});

test('RS485 opening fails before unlock when reconnect attempts are exhausted', async () => {
  const driver = new ControlledDriver();
  let connectAttempts = 0;
  driver.connect = async () => {
    connectAttempts++;
    throw new HardwareTransportError('adapter unavailable', true);
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], {
    maxAttempts: 3,
    delayMs: 0,
  });

  await assert.rejects(
    () => bus.flashRelay({ compartmentNumber: 1, slaveId: 1, relayAddress: 0 }, 200),
    /hardware bus unavailable/,
  );

  assert.equal(connectAttempts, 3);
  assert.deepEqual(driver.unlockCalls, []);
  assert.equal(bus.getConnectionState(), 'unreachable');
});

test('RS485 actor queries a board once and slices requested channels', async () => {
  const driver = new ControlledDriver();
  const bus = new Rs485LockBoardBusActor(driver, () => [1]);
  await bus.connect();

  assert.deepEqual(await bus.readDoorSensors(1, 1, 2), ['open', 'closed']);
  assert.deepEqual(await bus.readDoorSensors(1, 9, 1), ['unknown']);
});

test('RS485 actor closes and reopens after a malformed unlock frame', async () => {
  const driver = new ControlledDriver();
  let disconnects = 0;
  const originalDisconnect = driver.disconnect.bind(driver);
  driver.disconnect = async () => {
    disconnects++;
    return originalDisconnect();
  };
  let attempts = 0;
  driver.unlock = async () => {
    attempts++;
    if (attempts === 1) {
      throw new HardwareTransportError('invalid response BCC', true);
    }
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });
  await bus.connect();

  await bus.flashRelay({ compartmentNumber: 1, slaveId: 1, relayAddress: 0 }, 200);
  assert.equal(attempts, 2);
  assert.equal(disconnects, 1);
});

test('RS485 actor reconnects and retries one transport failure', async () => {
  const driver = new ControlledDriver();
  let attempts = 0;
  driver.unlock = async () => {
    attempts++;
    if (attempts === 1) {
      throw new HardwareTransportError('port closed', true);
    }
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });
  await bus.connect();

  await bus.flashRelay({ compartmentNumber: 1, slaveId: 1, relayAddress: 0 }, 200);
  assert.equal(attempts, 2);
  assert.equal(bus.getConnectionState(), 'connected');
});
