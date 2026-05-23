import ModbusRTU from "modbus-serial";
import { getModbusConfig } from "../config/modbus";
import { configLoader } from "../config/configLoader";
import { logger } from "../helper/logger";
import { SerialOperationQueue } from "../helper/serialOperationQueue";

interface WaveshareCommandClient extends ModbusRTU {
  customFunction(functionCode: number, data: Buffer): Promise<unknown>;
}

class ModbusService {
  private client: ModbusRTU | null = null;
  private isConnecting: boolean = false;
  private operationQueue: SerialOperationQueue = new SerialOperationQueue(
    (operationName, error) => {
      logger.debug(`Modbus queue operation failed: ${operationName}`, error);
    },
  );
  private reconnectAttempts: number = 0;
  private maxReconnectAttempts: number = 5;
  private reconnectDelay: number = 5000; // 5 seconds
  private readonly FLASH_ON_BASE_ADDRESS = 0x0200;
  private readonly FLASH_OFF_BASE_ADDRESS = 0x0400;
  private readonly ALL_RELAYS_ADDRESS = 0x00ff;
  private readonly FLASH_DURATION_STEP_MS = 100;
  private readonly FLASH_DURATION_MAX_STEPS = 0x7fff;
  private readonly LEGACY_DEFAULT_SLAVE_ID = 1;

  async connect(): Promise<void> {
    return this.enqueueOperation("connect", () => this.connectInternal());
  }

  private async connectInternal(): Promise<void> {
    if (this.isConnecting) {
      logger.debug("Connection already in progress");
      return;
    }

    this.isConnecting = true;

    try {
      // Close existing connection if any
      if (this.client) {
        try {
          await this.disconnectInternal();
        } catch (err) {
          logger.warn("Error closing existing connection:", err);
        }
      }

      // Create a new Modbus connection
      this.client = new ModbusRTU();

      const modbusConfig = getModbusConfig();
      const connectionConfig = modbusConfig.connection;

      await this.client.connectRTUBuffered(connectionConfig.port, {
        baudRate: connectionConfig.baudRate,
        dataBits: connectionConfig.dataBits,
        stopBits: connectionConfig.stopBits,
        parity: connectionConfig.parity,
      });

      this.client.setTimeout(connectionConfig.timeout);

      // Reset reconnect attempts on successful connection
      this.reconnectAttempts = 0;

      logger.info(`Modbus RTU connected to ${connectionConfig.port}`);
      logger.info(
        `Configured slave IDs: ${this.getConfiguredSlaveIds().join(", ")}`,
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
    return this.enqueueOperation("disconnect", () => this.disconnectInternal());
  }

  private async disconnectInternal(): Promise<void> {
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
    return this.enqueueOperation("reconnect", () => this.reconnectInternal());
  }

  private async reconnectInternal(): Promise<void> {
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
      await this.connectInternal();
      logger.info("Modbus reconnection successful");
    } catch (error) {
      logger.error(
        `Modbus reconnection attempt ${this.reconnectAttempts} failed:`,
        error,
      );

      if (this.reconnectAttempts < this.maxReconnectAttempts) {
        logger.info(`Will retry in ${this.reconnectDelay / 1000} seconds...`);
        setTimeout(() => {
          this.reconnect().catch((reconnectError) => {
            logger.error("Scheduled Modbus reconnect failed:", reconnectError);
          });
        }, this.reconnectDelay);
      }
    }
  }

  resetReconnectAttempts(): void {
    this.reconnectAttempts = 0;
  }

  async reloadRuntimeConfig(): Promise<void> {
    const wasConnected = this.isModbusConnected();

    logger.info(
      `Reloaded Modbus runtime config for slave IDs: ${this.getConfiguredSlaveIds().join(", ")}`,
    );

    if (wasConnected) {
      await this.connect();
    }
  }

  getClient(slaveId: number = this.LEGACY_DEFAULT_SLAVE_ID): ModbusRTU {
    if (!this.client) {
      throw new Error("Modbus client not connected");
    }

    this.client.setID(slaveId);
    return this.client;
  }

  getConfiguredSlaveIds(): number[] {
    const configuredCompartments = configLoader.getConfig()?.compartments;
    if (
      configLoader.hasExplicitRuntimeCompartmentsConfig() &&
      configuredCompartments?.length === 0
    ) {
      return [];
    }

    if (!configuredCompartments || configuredCompartments.length === 0) {
      return [this.LEGACY_DEFAULT_SLAVE_ID];
    }

    return [...new Set(configuredCompartments.map((compartment) => compartment.slaveId))]
      .sort((left, right) => left - right);
  }

  async writeCoil(
    address: number,
    value: boolean,
    slaveId: number = this.LEGACY_DEFAULT_SLAVE_ID,
  ): Promise<void> {
    return this.enqueueOperation(`writeCoil:${slaveId}:${address}`, async () => {
      const client = this.getClient(slaveId);

      try {
        await client.writeCoil(address, value);
        logger.debug(`[slave:${slaveId}] Wrote coil ${address}: ${value}`);
      } catch (error) {
        logger.error(`[slave:${slaveId}] Failed to write coil ${address}:`, error);
        throw error;
      }
    });
  }

  async flashRelayOn(
    address: number,
    durationMs: number,
    slaveId: number = this.LEGACY_DEFAULT_SLAVE_ID,
  ): Promise<void> {
    const flashAddress = this.FLASH_ON_BASE_ADDRESS + address;
    const flashDurationValue = this.toFlashDurationValue(durationMs);

    await this.writeRawFC5(flashAddress, flashDurationValue, slaveId);
    logger.debug(
      `[slave:${slaveId}] Triggered relay ${address} flash ON for ${flashDurationValue * this.FLASH_DURATION_STEP_MS}ms`,
    );
  }

  async flashRelayOff(
    address: number,
    durationMs: number,
    slaveId: number = this.LEGACY_DEFAULT_SLAVE_ID,
  ): Promise<void> {
    const flashAddress = this.FLASH_OFF_BASE_ADDRESS + address;
    const flashDurationValue = this.toFlashDurationValue(durationMs);

    await this.writeRawFC5(flashAddress, flashDurationValue, slaveId);
    logger.debug(
      `[slave:${slaveId}] Triggered relay ${address} flash OFF for ${flashDurationValue * this.FLASH_DURATION_STEP_MS}ms`,
    );
  }

  async turnAllRelaysOff(
    slaveId: number = this.LEGACY_DEFAULT_SLAVE_ID,
  ): Promise<void> {
    await this.writeRawFC5(this.ALL_RELAYS_ADDRESS, 0x0000, slaveId);
    logger.info(`[slave:${slaveId}] Turned all relays OFF`);
  }

  async writeRegister(
    address: number,
    value: number,
    slaveId: number = this.LEGACY_DEFAULT_SLAVE_ID,
  ): Promise<void> {
    return this.enqueueOperation(
      `writeRegister:${slaveId}:${address}`,
      async () => {
        const client = this.getClient(slaveId);

        try {
          await client.writeRegister(address, value);
          logger.debug(`[slave:${slaveId}] Wrote register ${address}: ${value}`);
        } catch (error) {
          logger.error(
            `[slave:${slaveId}] Failed to write register ${address}:`,
            error,
          );
          throw error;
        }
      },
    );
  }

  async readCoils(
    address: number,
    length: number,
    slaveId: number = this.LEGACY_DEFAULT_SLAVE_ID,
    options: { logErrors?: boolean } = {},
  ): Promise<boolean[]> {
    return this.enqueueOperation(`readCoils:${slaveId}:${address}`, async () => {
      const client = this.getClient(slaveId);

      try {
        const result = await client.readCoils(address, length);
        return result.data;
      } catch (error) {
        if (options.logErrors !== false) {
          logger.error(
            `[slave:${slaveId}] Failed to read coils from ${address}:`,
            error,
          );
        }
        throw error;
      }
    });
  }

  async readDiscreteInputs(
    address: number,
    length: number,
    slaveId: number = this.LEGACY_DEFAULT_SLAVE_ID,
    options: { logErrors?: boolean } = {},
  ): Promise<boolean[]> {
    return this.enqueueOperation(
      `readDiscreteInputs:${slaveId}:${address}`,
      async () => {
        const client = this.getClient(slaveId);

        try {
          const result = await client.readDiscreteInputs(address, length);
          return result.data;
        } catch (error) {
          if (options.logErrors !== false) {
            logger.error(
              `[slave:${slaveId}] Failed to read discrete inputs from ${address}:`,
              error,
            );
          }
          throw error;
        }
      },
    );
  }

  async readHoldingRegisters(
    address: number,
    length: number,
    slaveId: number = this.LEGACY_DEFAULT_SLAVE_ID,
  ): Promise<number[]> {
    return this.enqueueOperation(
      `readHoldingRegisters:${slaveId}:${address}`,
      async () => {
        const client = this.getClient(slaveId);

        try {
          const result = await client.readHoldingRegisters(address, length);
          return result.data;
        } catch (error) {
          logger.error(
            `[slave:${slaveId}] Failed to read holding registers from ${address}:`,
            error,
          );
          throw error;
        }
      },
    );
  }

  isModbusConnected(_slaveId?: number): boolean {
    if (!this.client) {
      return false;
    }

    // Check if the port is actually open
    if (!this.client.isOpen) {
      return false;
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

  private async writeRawFC5(
    dataAddress: number,
    value: number,
    slaveId: number,
  ): Promise<void> {
    return this.enqueueOperation(`rawFC5:${slaveId}:${dataAddress}`, async () => {
      const client = this.getClient(slaveId) as WaveshareCommandClient;
      if (typeof client.customFunction !== "function") {
        throw new Error(
          "modbus-serial customFunction API is unavailable. Install modbus-serial >= 8.0.23-no-serial-port.",
        );
      }

      const payload = Buffer.from([
        (dataAddress >> 8) & 0xff,
        dataAddress & 0xff,
        (value >> 8) & 0xff,
        value & 0xff,
      ]);

      try {
        await client.customFunction(0x05, payload);
      } catch (error) {
        logger.error(
          `[slave:${slaveId}] Failed raw FC05 write to ${dataAddress}:`,
          error,
        );
        throw error;
      }
    });
  }

  private enqueueOperation<T>(
    operationName: string,
    operation: () => Promise<T>,
  ): Promise<T> {
    return this.operationQueue.enqueue(operationName, operation);
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
