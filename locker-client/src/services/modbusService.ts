import ModbusRTU from "modbus-serial";
import { ModbusClientConfig, modbusConfig } from "../config/modbus";
import { logger } from "../helper/logger";

interface RawFC5Client extends ModbusRTU {
  writeCustomFC(
    address: number,
    functionCode: number,
    data: number[],
    next: (error?: Error | null) => void,
  ): void;
}

class ModbusService {
  private client: ModbusRTU | null = null;
  private clientConfigs: Map<string, ModbusClientConfig> = new Map();
  private slaveIdMap: Map<string, number> = new Map();
  private isConnecting: boolean = false;
  private reconnectAttempts: number = 0;
  private maxReconnectAttempts: number = 5;
  private reconnectDelay: number = 5000; // 5 seconds
  private readonly FLASH_ON_BASE_ADDRESS = 0x0200;
  private readonly FLASH_OFF_BASE_ADDRESS = 0x0400;
  private readonly ALL_RELAYS_ADDRESS = 0x00ff;
  private readonly FLASH_DURATION_STEP_MS = 100;
  private readonly FLASH_DURATION_MAX_STEPS = 0x7fff;

  constructor() {
    // Store client configurations and build slave ID mapping
    modbusConfig.clients.forEach((config) => {
      this.clientConfigs.set(config.id, config);
      this.slaveIdMap.set(config.id, config.slaveId);
    });
  }

  async connect(): Promise<void> {
    if (this.isConnecting) {
      logger.debug("Connection already in progress");
      return;
    }

    this.isConnecting = true;

    try {
      // Close existing connection if any
      if (this.client) {
        try {
          await this.disconnect();
        } catch (err) {
          logger.warn("Error closing existing connection:", err);
        }
      }

      // Create a new Modbus connection
      this.client = new ModbusRTU();

      // Use configuration from the first client for connection settings
      const firstConfig = Array.from(this.clientConfigs.values())[0];
      if (!firstConfig) {
        throw new Error("No Modbus client configurations found");
      }

      await this.client.connectRTUBuffered(firstConfig.port, {
        baudRate: firstConfig.baudRate,
        dataBits: firstConfig.dataBits,
        stopBits: firstConfig.stopBits,
        parity: firstConfig.parity,
      });

      this.client.setTimeout(firstConfig.timeout);

      // Reset reconnect attempts on successful connection
      this.reconnectAttempts = 0;

      logger.info(`Modbus RTU connected to ${firstConfig.port}`);
      logger.info(
        `Configured slave IDs: ${
          Array.from(this.slaveIdMap.entries()).map(([id, slaveId]) =>
            `${id}=${slaveId}`
          ).join(", ")
        }`,
      );
    } catch (error) {
      logger.error(`Failed to connect Modbus RTU:`, error);
      this.client = null;
      throw error;
    } finally {
      this.isConnecting = false;
    }
  }

  async disconnect(): Promise<void> {
    if (this.client) {
      await new Promise<void>((resolve) => {
        this.client!.close(() => {
          logger.info("Modbus RTU connection closed");
          resolve();
        });
      });
      this.client = null;
    }
  }

  async reconnect(): Promise<void> {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      logger.error(
        `Max Modbus reconnection attempts (${this.maxReconnectAttempts}) reached. Manual intervention required.`,
      );
      return;
    }

    this.reconnectAttempts++;
    logger.info(
      `Attempting to reconnect Modbus (attempt ${this.reconnectAttempts}/${this.maxReconnectAttempts})...`,
    );

    try {
      await this.connect();
      logger.info("Modbus reconnection successful");
    } catch (error) {
      logger.error(
        `Modbus reconnection attempt ${this.reconnectAttempts} failed:`,
        error,
      );

      if (this.reconnectAttempts < this.maxReconnectAttempts) {
        logger.info(`Will retry in ${this.reconnectDelay / 1000} seconds...`);
        setTimeout(() => {
          this.reconnect();
        }, this.reconnectDelay);
      }
    }
  }

  resetReconnectAttempts(): void {
    this.reconnectAttempts = 0;
  }

  getClient(clientId: string = "default"): ModbusRTU {
    if (!this.client) {
      throw new Error("Modbus client not connected");
    }

    // Set the slave ID for this client
    const slaveId = this.getSlaveId(clientId);

    this.client.setID(slaveId);
    return this.client;
  }

  getClientIds(): string[] {
    return Array.from(this.slaveIdMap.keys());
  }

  async writeCoil(
    address: number,
    value: boolean,
    clientId: string = "default",
  ): Promise<void> {
    const client = this.getClient(clientId);

    try {
      await client.writeCoil(address, value);
      logger.debug(`[${clientId}] Wrote coil ${address}: ${value}`);
    } catch (error) {
      logger.error(`[${clientId}] Failed to write coil ${address}:`, error);
      throw error;
    }
  }

  async flashRelayOn(
    address: number,
    durationMs: number,
    clientId: string = "default",
  ): Promise<void> {
    const flashAddress = this.FLASH_ON_BASE_ADDRESS + address;
    const flashDurationValue = this.toFlashDurationValue(durationMs);

    await this.writeRawFC5(flashAddress, flashDurationValue, clientId);
    logger.debug(
      `[${clientId}] Triggered relay ${address} flash ON for ${flashDurationValue * this.FLASH_DURATION_STEP_MS}ms`,
    );
  }

  async flashRelayOff(
    address: number,
    durationMs: number,
    clientId: string = "default",
  ): Promise<void> {
    const flashAddress = this.FLASH_OFF_BASE_ADDRESS + address;
    const flashDurationValue = this.toFlashDurationValue(durationMs);

    await this.writeRawFC5(flashAddress, flashDurationValue, clientId);
    logger.debug(
      `[${clientId}] Triggered relay ${address} flash OFF for ${flashDurationValue * this.FLASH_DURATION_STEP_MS}ms`,
    );
  }

  async turnAllRelaysOff(clientId: string = "default"): Promise<void> {
    await this.writeRawFC5(this.ALL_RELAYS_ADDRESS, 0x0000, clientId);
    logger.info(`[${clientId}] Turned all relays OFF`);
  }

  async writeRegister(
    address: number,
    value: number,
    clientId: string = "default",
  ): Promise<void> {
    const client = this.getClient(clientId);

    try {
      await client.writeRegister(address, value);
      logger.debug(`[${clientId}] Wrote register ${address}: ${value}`);
    } catch (error) {
      logger.error(`[${clientId}] Failed to write register ${address}:`, error);
      throw error;
    }
  }

  async readCoils(
    address: number,
    length: number,
    clientId: string = "default",
  ): Promise<boolean[]> {
    const client = this.getClient(clientId);

    try {
      const result = await client.readCoils(address, length);
      return result.data;
    } catch (error) {
      logger.error(
        `[${clientId}] Failed to read coils from ${address}:`,
        error,
      );
      throw error;
    }
  }

  async readDiscreteInputs(
    address: number,
    length: number,
    clientId: string = "default",
  ): Promise<boolean[]> {
    const client = this.getClient(clientId);

    try {
      const result = await client.readDiscreteInputs(address, length);
      return result.data;
    } catch (error) {
      logger.error(
        `[${clientId}] Failed to read discrete inputs from ${address}:`,
        error,
      );
      throw error;
    }
  }

  async readHoldingRegisters(
    address: number,
    length: number,
    clientId: string = "default",
  ): Promise<number[]> {
    const client = this.getClient(clientId);

    try {
      const result = await client.readHoldingRegisters(address, length);
      return result.data;
    } catch (error) {
      logger.error(
        `[${clientId}] Failed to read holding registers from ${address}:`,
        error,
      );
      throw error;
    }
  }

  isModbusConnected(clientId?: string): boolean {
    if (!this.client) {
      return false;
    }

    // Check if the port is actually open
    if (!this.client.isOpen) {
      return false;
    }

    if (clientId) {
      return this.slaveIdMap.has(clientId);
    }
    return true;
  }

  async ensureConnection(): Promise<boolean> {
    if (this.isModbusConnected()) {
      return true;
    }

    logger.warn("Modbus connection lost, attempting to reconnect...");

    try {
      await this.reconnect();
      return this.isModbusConnected();
    } catch (error) {
      logger.error("Failed to ensure Modbus connection:", error);
      return false;
    }
  }

  private getSlaveId(clientId: string): number {
    const slaveId = this.slaveIdMap.get(clientId);
    if (slaveId === undefined) {
      throw new Error(`Client '${clientId}' not found in configuration`);
    }

    return slaveId;
  }

  private async writeRawFC5(
    dataAddress: number,
    value: number,
    clientId: string,
  ): Promise<void> {
    const client = this.getClient(clientId) as RawFC5Client;
    const slaveId = this.getSlaveId(clientId);
    const payload = [
      (dataAddress >> 8) & 0xff,
      dataAddress & 0xff,
      (value >> 8) & 0xff,
      value & 0xff,
    ];

    await new Promise<void>((resolve, reject) => {
      client.writeCustomFC(slaveId, 0x05, payload, (error?: Error | null) => {
        if (error) {
          logger.error(
            `[${clientId}] Failed raw FC05 write to ${dataAddress}:`,
            error,
          );
          reject(error);
          return;
        }

        resolve();
      });
    });
  }

  private toFlashDurationValue(durationMs: number): number {
    if (!Number.isFinite(durationMs) || durationMs <= 0) {
      throw new Error("Flash duration must be a positive number");
    }

    const flashDurationValue = Math.ceil(
      durationMs / this.FLASH_DURATION_STEP_MS,
    );

    if (flashDurationValue > this.FLASH_DURATION_MAX_STEPS) {
      throw new Error("Flash duration exceeds Waveshare maximum value");
    }

    return flashDurationValue;
  }
}

export const modbusService = new ModbusService();
