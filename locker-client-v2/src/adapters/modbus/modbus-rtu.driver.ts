import ModbusRTU from 'modbus-serial';
import type { ModbusDriver } from './bus-actor';
import { flashRelayOn, turnAllRelaysOff, type WaveshareModbusClient } from './waveshare-flash';

interface ModbusConnectionConfig {
  port: string;
  baudRate: number;
  dataBits: 7 | 8;
  stopBits: 1 | 2;
  parity: 'none' | 'even' | 'odd';
  timeout: number;
}

export class ModbusRtuDriver implements ModbusDriver {
  private client: ModbusRTU | null = null;

  constructor(private readonly connection: ModbusConnectionConfig) {}

  async connect(): Promise<void> {
    if (this.client?.isOpen) {
      return;
    }

    this.client = new ModbusRTU();
    await this.client.connectRTUBuffered(this.connection.port, {
      baudRate: this.connection.baudRate,
      dataBits: this.connection.dataBits,
      stopBits: this.connection.stopBits,
      parity: this.connection.parity,
    });
    this.client.setTimeout(this.connection.timeout);
  }

  async disconnect(): Promise<void> {
    if (!this.client) {
      return;
    }

    await new Promise<void>((resolve) => {
      this.client!.close(() => resolve());
    });
    this.client = null;
  }

  isOpen(): boolean {
    return Boolean(this.client?.isOpen);
  }

  async flashRelayOn(slaveId: number, address: number, durationMs: number): Promise<void> {
    await flashRelayOn(this.getWaveshareClient(), slaveId, address, durationMs);
  }

  async readCoils(slaveId: number, address: number, length: number): Promise<boolean[]> {
    this.getClient().setID(slaveId);
    const result = await this.getClient().readCoils(address, length);
    return result.data;
  }

  async readDiscreteInputs(slaveId: number, address: number, length: number): Promise<boolean[]> {
    this.getClient().setID(slaveId);
    const result = await this.getClient().readDiscreteInputs(address, length);
    return result.data;
  }

  async turnAllRelaysOff(slaveId: number): Promise<void> {
    await turnAllRelaysOff(this.getWaveshareClient(), slaveId);
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
}
