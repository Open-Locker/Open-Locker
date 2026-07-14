import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ModbusBusActor, type ModbusDriver } from '../../src/adapters/modbus/bus-actor';
import { BusPriority } from '../../src/ports/locker-bus.port';

class FakeModbusDriver implements ModbusDriver {
  readonly operations: string[] = [];
  private open = false;

  async connect(): Promise<void> {
    this.operations.push('connect');
    this.open = true;
  }

  async disconnect(): Promise<void> {
    this.operations.push('disconnect');
    this.open = false;
  }

  isOpen(): boolean {
    return this.open;
  }

  async flashRelayOn(slaveId: number, address: number, durationMs: number): Promise<void> {
    this.operations.push(`flash:${slaveId}:${address}:${durationMs}`);
    await delay(20);
  }

  async readCoils(_slaveId: number, _address: number, _length: number): Promise<boolean[]> {
    this.operations.push('readCoils');
    await delay(20);
    return [false];
  }

  async readDiscreteInputs(
    _slaveId: number,
    _address: number,
    _length: number,
  ): Promise<boolean[]> {
    this.operations.push('readDiscreteInputs');
    return [true];
  }

  async turnAllRelaysOff(slaveId: number): Promise<void> {
    this.operations.push(`allOff:${slaveId}`);
  }
}

test('BusActor serializes concurrent operations', async () => {
  const driver = new FakeModbusDriver();
  const bus = new ModbusBusActor(driver, { maxAttempts: 0 }, [1]);

  await bus.connect();
  const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };

  const first = bus.flashRelay(target, 200);
  const second = bus.readRelayState(target);
  await Promise.all([first, second]);

  const flashIndex = driver.operations.indexOf('flash:1:0:200');
  const readIndex = driver.operations.indexOf('readCoils');
  assert.ok(flashIndex >= 0);
  assert.ok(readIndex > flashIndex);
});

test('BusActor command priority runs before poll reads', async () => {
  const driver = new FakeModbusDriver();
  const bus = new ModbusBusActor(driver, { maxAttempts: 0 }, [1]);
  await bus.connect();

  const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };
  const queue = bus.getQueue();

  void queue.add(
    async () => {
      driver.operations.push('slowPoll');
      await delay(50);
    },
    { priority: BusPriority.POLL },
  );

  await delay(5);
  await bus.flashRelay(target, 200);

  const slowPollIndex = driver.operations.indexOf('slowPoll');
  const flashIndex = driver.operations.findIndex((op) => op.startsWith('flash:'));
  assert.ok(slowPollIndex >= 0);
  assert.ok(flashIndex > slowPollIndex);
});

test('ensureConnected returns false after max reconnect attempts', async () => {
  const driver = new FailingConnectDriver();
  const bus = new ModbusBusActor(driver, { maxAttempts: 3, delayMs: 1 }, [1]);

  const result = await bus.ensureConnected();

  assert.equal(result, false);
  assert.equal(driver.connectAttempts, 3);
});

test('concurrent flashRelay and ensureConnected never interleave driver calls', async () => {
  const driver = new InterleavingGuardDriver();
  const bus = new ModbusBusActor(driver, { maxAttempts: 0 }, [1]);
  await bus.connect();

  const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };
  driver.markClosed();

  await Promise.all([bus.flashRelay(target, 200), bus.ensureConnected()]);

  assert.equal(driver.hadInterleavedCalls, false);
  assert.ok(driver.operations.includes('connect'));
  assert.ok(driver.operations.some((op) => op.startsWith('flash:')));
});

test('readDoorSensor maps discrete input high to closed and low to open', async () => {
  const driver = new ConfigurableDiscreteInputDriver(true);
  const bus = new ModbusBusActor(driver, { maxAttempts: 0 }, [1]);
  await bus.connect();

  const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };
  assert.equal(await bus.readDoorSensor(target), 'closed');

  driver.setInputState(false);
  assert.equal(await bus.readDoorSensor(target), 'open');
});

test('BusActor retries once after reconnectable transport failure', async () => {
  const driver = new ReconnectableFailureDriver();
  const bus = new ModbusBusActor(driver, { maxAttempts: 0 }, [1]);
  await bus.connect();

  const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };
  await bus.flashRelay(target, 200);

  assert.equal(driver.flashAttempts, 2);
  assert.ok(driver.operations.includes('disconnect'));
  assert.ok(driver.operations.filter((op) => op === 'connect').length >= 2);
});

class ConfigurableDiscreteInputDriver implements ModbusDriver {
  private open = false;

  constructor(private inputState: boolean) {}

  setInputState(value: boolean): void {
    this.inputState = value;
  }

  async connect(): Promise<void> {
    this.open = true;
  }

  async disconnect(): Promise<void> {
    this.open = false;
  }

  isOpen(): boolean {
    return this.open;
  }

  async flashRelayOn(): Promise<void> {}

  async readCoils(): Promise<boolean[]> {
    return [false];
  }

  async readDiscreteInputs(): Promise<boolean[]> {
    return [this.inputState];
  }

  async turnAllRelaysOff(): Promise<void> {}
}

class FailingConnectDriver implements ModbusDriver {
  connectAttempts = 0;

  async connect(): Promise<void> {
    this.connectAttempts++;
    throw new Error('connect failed');
  }

  async disconnect(): Promise<void> {}

  isOpen(): boolean {
    return false;
  }

  async flashRelayOn(): Promise<void> {}

  async readCoils(): Promise<boolean[]> {
    return [false];
  }

  async readDiscreteInputs(): Promise<boolean[]> {
    return [true];
  }

  async turnAllRelaysOff(): Promise<void> {}
}

class InterleavingGuardDriver implements ModbusDriver {
  readonly operations: string[] = [];
  private open = false;
  private activeOperation: string | null = null;
  hadInterleavedCalls = false;

  async connect(): Promise<void> {
    await this.runExclusive('connect', async () => {
      this.open = true;
    });
  }

  async disconnect(): Promise<void> {
    await this.runExclusive('disconnect', async () => {
      this.open = false;
    });
  }

  isOpen(): boolean {
    return this.open;
  }

  markClosed(): void {
    this.open = false;
  }

  async flashRelayOn(slaveId: number, address: number, durationMs: number): Promise<void> {
    await this.runExclusive(`flash:${slaveId}:${address}:${durationMs}`, async () => {
      await delay(30);
    });
  }

  async readCoils(): Promise<boolean[]> {
    return [false];
  }

  async readDiscreteInputs(): Promise<boolean[]> {
    return [true];
  }

  async turnAllRelaysOff(slaveId: number): Promise<void> {
    this.operations.push(`allOff:${slaveId}`);
  }

  private async runExclusive(label: string, fn: () => Promise<void>): Promise<void> {
    if (this.activeOperation !== null) {
      this.hadInterleavedCalls = true;
    }

    this.activeOperation = label;
    this.operations.push(label);
    try {
      await fn();
    } finally {
      this.activeOperation = null;
    }
  }
}

class ReconnectableFailureDriver implements ModbusDriver {
  readonly operations: string[] = [];
  flashAttempts = 0;
  private open = false;

  async connect(): Promise<void> {
    this.operations.push('connect');
    this.open = true;
  }

  async disconnect(): Promise<void> {
    this.operations.push('disconnect');
    this.open = false;
  }

  isOpen(): boolean {
    return this.open;
  }

  async flashRelayOn(slaveId: number, address: number, durationMs: number): Promise<void> {
    this.flashAttempts++;
    if (this.flashAttempts === 1) {
      throw new Error('Port Not Open');
    }
    this.operations.push(`flash:${slaveId}:${address}:${durationMs}`);
  }

  async readCoils(): Promise<boolean[]> {
    return [false];
  }

  async readDiscreteInputs(): Promise<boolean[]> {
    return [true];
  }

  async turnAllRelaysOff(slaveId: number): Promise<void> {
    this.operations.push(`allOff:${slaveId}`);
  }
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
