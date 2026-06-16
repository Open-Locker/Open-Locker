import assert from "node:assert/strict";
import { test } from "node:test";
import { ModbusBusActor, type ModbusDriver } from "../../src/adapters/modbus/bus-actor";
import { BusPriority } from "../../src/ports/locker-bus.port";

class FakeModbusDriver implements ModbusDriver {
  readonly operations: string[] = [];
  private open = false;

  async connect(): Promise<void> {
    this.operations.push("connect");
    this.open = true;
  }

  async disconnect(): Promise<void> {
    this.operations.push("disconnect");
    this.open = false;
  }

  isOpen(): boolean {
    return this.open;
  }

  async flashRelayOn(
    slaveId: number,
    address: number,
    durationMs: number,
  ): Promise<void> {
    this.operations.push(`flash:${slaveId}:${address}:${durationMs}`);
    await delay(20);
  }

  async readCoils(
    _slaveId: number,
    _address: number,
    _length: number,
  ): Promise<boolean[]> {
    this.operations.push("readCoils");
    await delay(20);
    return [false];
  }

  async readDiscreteInputs(
    _slaveId: number,
    _address: number,
    _length: number,
  ): Promise<boolean[]> {
    this.operations.push("readDiscreteInputs");
    return [true];
  }

  async turnAllRelaysOff(slaveId: number): Promise<void> {
    this.operations.push(`allOff:${slaveId}`);
  }
}

test("BusActor serializes concurrent operations", async () => {
  const driver = new FakeModbusDriver();
  const bus = new ModbusBusActor(driver, { maxAttempts: 0 }, [1]);

  await bus.connect();
  const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };

  const first = bus.flashRelay(target, 200);
  const second = bus.readRelayState(target);
  await Promise.all([first, second]);

  const flashIndex = driver.operations.indexOf("flash:1:0:200");
  const readIndex = driver.operations.indexOf("readCoils");
  assert.ok(flashIndex >= 0);
  assert.ok(readIndex > flashIndex);
});

test("BusActor command priority runs before poll reads", async () => {
  const driver = new FakeModbusDriver();
  const bus = new ModbusBusActor(driver, { maxAttempts: 0 }, [1]);
  await bus.connect();

  const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };
  const queue = bus.getQueue();

  void queue.add(async () => {
    driver.operations.push("slowPoll");
    await delay(50);
  }, { priority: BusPriority.POLL });

  await delay(5);
  await bus.flashRelay(target, 200);

  const slowPollIndex = driver.operations.indexOf("slowPoll");
  const flashIndex = driver.operations.findIndex((op) =>
    op.startsWith("flash:"),
  );
  assert.ok(slowPollIndex >= 0);
  assert.ok(flashIndex > slowPollIndex);
});

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
