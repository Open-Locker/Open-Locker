import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Rs485LockBoardBusActor } from '../../src/adapters/rs485/rs485-lock-board-bus-actor';
import type { Rs485LockBoardDriverPort } from '../../src/adapters/rs485/rs485-lock-board.driver';
import {
  BoardNotRespondingError,
  HardwareTransportError,
  UnlockNotSentError,
  UnlockOutcomeUnknownError,
} from '../../src/domain/errors';

const TARGET = { compartmentNumber: 1, boardAddress: 1, address: 0 };

class ControlledDriver implements Rs485LockBoardDriverPort {
  open = false;
  active = 0;
  maximumActive = 0;
  unlockCalls: number[] = [];
  disconnects = 0;

  async connect(): Promise<void> {
    this.open = true;
  }
  async disconnect(): Promise<void> {
    this.disconnects++;
    this.open = false;
  }
  isOpen(): boolean {
    return this.open;
  }
  async unlock(_board: number, channel: number): Promise<'open' | 'closed'> {
    this.active++;
    this.maximumActive = Math.max(this.maximumActive, this.active);
    this.unlockCalls.push(channel);
    await new Promise((resolve) => setTimeout(resolve, 5));
    this.active--;
    return 'open';
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
    bus.unlockCompartment(TARGET),
    bus.unlockCompartment({ compartmentNumber: 2, boardAddress: 1, address: 1 }),
  ]);

  assert.equal(driver.maximumActive, 1);
  assert.deepEqual(driver.unlockCalls, [0, 1]);
});

test('RS485 unlock reports the door state from the board response', async () => {
  const driver = new ControlledDriver();
  driver.unlock = async () => 'closed';
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });
  await bus.connect();

  assert.deepEqual(await bus.unlockCompartment(TARGET), { doorState: 'closed' });
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
    return 'open';
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });

  await bus.unlockCompartment(TARGET);

  assert.deepEqual(operations, ['connect', 'unlock']);
});

test('RS485 opening fails as not sent when reconnect attempts are exhausted', async () => {
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
    () => bus.unlockCompartment(TARGET),
    (error: unknown) =>
      error instanceof UnlockNotSentError && /hardware bus unavailable/.test(error.message),
  );

  assert.equal(connectAttempts, 3);
  assert.deepEqual(driver.unlockCalls, []);
  assert.equal(bus.getConnectionState(), 'unreachable');
});

test('RS485 actor queries a board once and returns the requested addresses', async () => {
  const driver = new ControlledDriver();
  const bus = new Rs485LockBoardBusActor(driver, () => [1]);
  await bus.connect();

  assert.deepEqual(await bus.readCompartmentStates(1, [1, 2]), ['open', 'closed']);
  assert.deepEqual(await bus.readCompartmentStates(1, [9]), ['unknown']);
});

test('RS485 actor rejects a read when the board does not answer', async () => {
  const driver = new ControlledDriver();
  driver.queryAll = async () => {
    throw new BoardNotRespondingError('RS485 board 1 did not respond', false);
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1]);
  await bus.connect();

  await assert.rejects(() => bus.readCompartmentStates(1, [0]), BoardNotRespondingError);
});

test('RS485 actor reconnects after an unlock with an unknown outcome but never re-sends it', async () => {
  const driver = new ControlledDriver();
  let attempts = 0;
  driver.unlock = async () => {
    attempts++;
    throw new UnlockOutcomeUnknownError('RS485 response timed out after 1500ms', true);
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });
  await bus.connect();

  await assert.rejects(() => bus.unlockCompartment(TARGET), UnlockOutcomeUnknownError);

  assert.equal(attempts, 1, 'the lock may have released: a second unlock is not sent');
  assert.equal(driver.disconnects, 1);
  assert.equal(bus.getConnectionState(), 'connected');
});

test('an unlock with an unknown outcome stays reported as such when the reconnect fails', async () => {
  const driver = new ControlledDriver();
  driver.unlock = async () => {
    throw new UnlockOutcomeUnknownError('RS485 serial error: device disconnected', true);
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { maxAttempts: 1, delayMs: 0 });
  await bus.connect();
  driver.connect = async () => {
    throw new HardwareTransportError('adapter unavailable', true);
  };

  await assert.rejects(() => bus.unlockCompartment(TARGET), UnlockOutcomeUnknownError);
  assert.equal(bus.getConnectionState(), 'unreachable');
});

test('RS485 actor reconnects and re-sends an unlock that never left the client', async () => {
  const driver = new ControlledDriver();
  let attempts = 0;
  driver.unlock = async () => {
    attempts++;
    if (attempts === 1) {
      throw new UnlockNotSentError('RS485 port is not open', true);
    }
    return 'open';
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });
  await bus.connect();

  assert.deepEqual(await bus.unlockCompartment(TARGET), { doorState: 'open' });
  assert.equal(attempts, 2);
  assert.equal(bus.getConnectionState(), 'connected');
});

test('RS485 actor reconnects after a silent board but does not query it again', async () => {
  const driver = new ControlledDriver();
  let queries = 0;
  driver.queryAll = async () => {
    queries++;
    throw new BoardNotRespondingError('RS485 board 1 did not respond');
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });
  await bus.connect();

  await assert.rejects(() => bus.readCompartmentStates(1, [0]), BoardNotRespondingError);

  assert.equal(queries, 1);
  assert.equal(driver.disconnects, 1);
  assert.equal(bus.getConnectionState(), 'connected');
});

test('RS485 actor reconnects and retries a failed read', async () => {
  const driver = new ControlledDriver();
  let attempts = 0;
  driver.queryAll = async () => {
    attempts++;
    if (attempts === 1) {
      throw new HardwareTransportError('invalid response BCC', true);
    }
    return ['open'];
  };
  const bus = new Rs485LockBoardBusActor(driver, () => [1], { delayMs: 0 });
  await bus.connect();

  assert.deepEqual(await bus.readCompartmentStates(1, [0]), ['open']);
  assert.equal(attempts, 2);
  assert.equal(driver.disconnects, 1);
});
