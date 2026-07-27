import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  calculateModbusRtuInterFrameDelayMs,
  WaveshareModbusRtuDriver,
  type ModbusConnectionConfig,
} from '../../src/adapters/modbus/waveshare-modbus-rtu.driver';

const connection: ModbusConnectionConfig = {
  port: '/dev/test',
  baudRate: 9600,
  dataBits: 8,
  stopBits: 1,
  parity: 'none',
  timeout: 1000,
};

test('calculates hardware-validated RTU inter-frame delay for 9600 8N1', () => {
  assert.equal(calculateModbusRtuInterFrameDelayMs(connection), 5);
});

test('uses fixed high-baud RTU delay with timer safety margin', () => {
  assert.equal(
    calculateModbusRtuInterFrameDelayMs({
      ...connection,
      baudRate: 115_200,
    }),
    3,
  );
});

test('waits for the RTU inter-frame delay between transactions', async () => {
  let now = 100;
  const sleepCalls: number[] = [];
  const client = new FakeModbusClient();
  const driver = new WaveshareModbusRtuDriver(connection, {
    now: () => now,
    sleep: async (delayMs) => {
      sleepCalls.push(delayMs);
      now += delayMs;
    },
  });
  setClient(driver, client);

  await driver.readDiscreteInputs(1, 0, 1);
  now += 1;
  await driver.readDiscreteInputs(2, 1, 1);

  assert.deepEqual(sleepCalls, [4]);
  assert.deepEqual(client.slaveIds, [1, 2]);
});

test('waits after a failed transaction before addressing the next board', async () => {
  let now = 100;
  const sleepCalls: number[] = [];
  const client = new FakeModbusClient(true);
  const driver = new WaveshareModbusRtuDriver(connection, {
    now: () => now,
    sleep: async (delayMs) => {
      sleepCalls.push(delayMs);
      now += delayMs;
    },
  });
  setClient(driver, client);

  await assert.rejects(() => driver.readDiscreteInputs(1, 0, 1), /Timed out/);
  await driver.readDiscreteInputs(2, 1, 1);

  assert.deepEqual(sleepCalls, [5]);
  assert.deepEqual(client.slaveIds, [1, 2]);
});

class FakeModbusClient {
  readonly isOpen = true;
  readonly slaveIds: number[] = [];
  private failNextRead: boolean;

  constructor(failNextRead = false) {
    this.failNextRead = failNextRead;
  }

  setID(slaveId: number): void {
    this.slaveIds.push(slaveId);
  }

  async readDiscreteInputs(): Promise<{ data: boolean[] }> {
    if (this.failNextRead) {
      this.failNextRead = false;
      throw new Error('Timed out');
    }

    return { data: [true] };
  }
}

function setClient(driver: WaveshareModbusRtuDriver, client: FakeModbusClient): void {
  (driver as unknown as { client: FakeModbusClient }).client = client;
}
