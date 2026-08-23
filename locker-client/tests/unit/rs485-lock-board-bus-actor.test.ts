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
  async unlock(_board: number, channel: number): Promise<'opened'> {
    this.active++;
    this.maximumActive = Math.max(this.maximumActive, this.active);
    this.unlockCalls.push(channel);
    await new Promise((resolve) => setTimeout(resolve, 5));
    this.active--;
    return 'opened';
  }
  async queryAll(): Promise<Array<'open' | 'closed'>> {
    return ['closed', 'open', 'closed', 'open'];
  }
}

test('RS485 actor serializes transactions and returns board feedback', async () => {
  const driver = new ControlledDriver();
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });
  await bus.connect();

  const results = await Promise.all([
    bus.flashRelay({ compartmentNumber: 1, slaveId: 1, relayAddress: 0 }, 200),
    bus.flashRelay({ compartmentNumber: 2, slaveId: 1, relayAddress: 1 }, 200),
  ]);

  assert.deepEqual(results, ['opened', 'opened']);
  assert.equal(driver.maximumActive, 1);
  assert.deepEqual(driver.unlockCalls, [0, 1]);
});

test('RS485 actor queries a board once and slices requested channels', async () => {
  const driver = new ControlledDriver();
  const bus = new Rs485LockBoardBusActor(driver, () => [1]);
  await bus.connect();

  assert.deepEqual(await bus.readDoorSensors(1, 1, 2), ['open', 'closed']);
  assert.equal(
    await bus.readRelayState({ compartmentNumber: 1, slaveId: 1, relayAddress: 0 }),
    false,
  );
});

test('RS485 actor reconnects and retries one transport failure', async () => {
  const driver = new ControlledDriver();
  let attempts = 0;
  driver.unlock = async () => {
    attempts++;
    if (attempts === 1) {
      throw new HardwareTransportError('port closed', true);
    }
    return 'opened';
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });
  await bus.connect();

  assert.equal(
    await bus.flashRelay({ compartmentNumber: 1, slaveId: 1, relayAddress: 0 }, 200),
    'opened',
  );
  assert.equal(attempts, 2);
  assert.equal(bus.getConnectionState(), 'connected');
});
