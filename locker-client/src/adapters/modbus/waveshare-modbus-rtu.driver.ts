import ModbusRTU from 'modbus-serial';
import { performance } from 'node:perf_hooks';
import type { WaveshareModbusDriver } from './waveshare-modbus-bus-actor';
import { flashRelayOn, turnAllRelaysOff, type WaveshareModbusClient } from './waveshare-flash';

export interface ModbusConnectionConfig {
  port: string;
  baudRate: number;
  dataBits: 7 | 8;
  stopBits: 1 | 2;
  parity: 'none' | 'even' | 'odd';
  timeout: number;
}

interface TimingDependencies {
  now(): number;
  sleep(delayMs: number): Promise<void>;
}

const MODBUS_RTU_HIGH_BAUD_DELAY_MS = 1.75;
const TIMER_SAFETY_MARGIN_MS = 1;

export function calculateModbusRtuInterFrameDelayMs(
  connection: Pick<ModbusConnectionConfig, 'baudRate' | 'dataBits' | 'stopBits' | 'parity'>,
): number {
  const parityBits = connection.parity === 'none' ? 0 : 1;
  const bitsPerCharacter = 1 + connection.dataBits + parityBits + connection.stopBits;
  const specificationDelayMs =
    connection.baudRate > 19_200
      ? MODBUS_RTU_HIGH_BAUD_DELAY_MS
      : (3.5 * bitsPerCharacter * 1000) / connection.baudRate;

  return Math.ceil(specificationDelayMs + TIMER_SAFETY_MARGIN_MS);
}

export class WaveshareModbusRtuDriver implements WaveshareModbusDriver {
  private client: ModbusRTU | null = null;
  private readonly interFrameDelayMs: number;
  private readonly timing: TimingDependencies;
  private lastTransactionCompletedAt: number | null = null;

  constructor(
    private readonly connection: ModbusConnectionConfig,
    timing: Partial<TimingDependencies> = {},
  ) {
    this.interFrameDelayMs = calculateModbusRtuInterFrameDelayMs(connection);
    this.timing = {
      now: timing.now ?? (() => performance.now()),
      sleep: timing.sleep ?? ((delayMs) => new Promise((resolve) => setTimeout(resolve, delayMs))),
    };
  }

  async connect(): Promise<void> {
    if (this.client?.isOpen) {
      return;
    }

    if (this.client) {
      try {
        await this.disconnect();
      } catch {
        this.client = null;
      }
    }

    this.client = new ModbusRTU();
    try {
      await this.client.connectRTUBuffered(this.connection.port, {
        baudRate: this.connection.baudRate,
        dataBits: this.connection.dataBits,
        stopBits: this.connection.stopBits,
        parity: this.connection.parity,
      });
      this.client.setTimeout(this.connection.timeout);
    } catch (error) {
      this.client = null;
      throw error;
    }
  }

  async disconnect(): Promise<void> {
    if (!this.client) {
      return;
    }

    await new Promise<void>((resolve) => {
      this.client!.close(() => resolve());
    });
    this.client = null;
    this.lastTransactionCompletedAt = null;
  }

  isOpen(): boolean {
    return Boolean(this.client?.isOpen);
  }

  async flashRelayOn(slaveId: number, address: number, durationMs: number): Promise<void> {
    await this.runTransaction(() =>
      flashRelayOn(this.getWaveshareClient(), slaveId, address, durationMs),
    );
  }

  async readCoils(slaveId: number, address: number, length: number): Promise<boolean[]> {
    return this.runTransaction(async () => {
      this.getClient().setID(slaveId);
      const result = await this.getClient().readCoils(address, length);
      return result.data;
    });
  }

  async readDiscreteInputs(slaveId: number, address: number, length: number): Promise<boolean[]> {
    return this.runTransaction(async () => {
      this.getClient().setID(slaveId);
      const result = await this.getClient().readDiscreteInputs(address, length);
      return result.data;
    });
  }

  async turnAllRelaysOff(slaveId: number): Promise<void> {
    await this.runTransaction(() => turnAllRelaysOff(this.getWaveshareClient(), slaveId));
  }

  private getClient(): ModbusRTU {
    if (!this.client?.isOpen) {
      throw new Error('Port Not Open');
    }
    return this.client;
  }

  private getWaveshareClient(): WaveshareModbusClient {
    const client = this.getClient() as ModbusRTU & WaveshareModbusClient;
    if (typeof client.customFunction !== 'function') {
      throw new Error('modbus-serial customFunction API is unavailable');
    }
    return client;
  }

  private async runTransaction<T>(operation: () => Promise<T>): Promise<T> {
    await this.waitForInterFrameDelay();
    try {
      return await operation();
    } finally {
      this.lastTransactionCompletedAt = this.timing.now();
    }
  }

  private async waitForInterFrameDelay(): Promise<void> {
    if (this.lastTransactionCompletedAt === null) {
      return;
    }

    const elapsedMs = this.timing.now() - this.lastTransactionCompletedAt;
    const remainingMs = this.interFrameDelayMs - elapsedMs;
    if (remainingMs > 0) {
      await this.timing.sleep(remainingMs);
    }
  }
}
