import ModbusRTU from "modbus-serial";
import { modbusConfig, ModbusClientConfig } from "../config/modbus";
import { logger } from "../helper/logger";

class ModbusService {
  private client: ModbusRTU | null = null;
  private clientConfigs: Map<string, ModbusClientConfig> = new Map();
  private slaveIdMap: Map<string, number> = new Map();

  constructor() {
    // Store client configurations and build slave ID mapping
    modbusConfig.clients.forEach(config => {
      this.clientConfigs.set(config.id, config);
      this.slaveIdMap.set(config.id, config.slaveId);
    });
  }

  async connect(): Promise<void> {
    try {
      // Create a single Modbus connection
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
      
      logger.info(`Modbus RTU connected to ${firstConfig.port}`);
      logger.info(`Configured slave IDs: ${Array.from(this.slaveIdMap.entries()).map(([id, slaveId]) => `${id}=${slaveId}`).join(', ')}`);
    } catch (error) {
      logger.error(`Failed to connect Modbus RTU:`, error);
      throw error;
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

  getClient(clientId: string = "default"): ModbusRTU {
    if (!this.client) {
      throw new Error("Modbus client not connected");
    }
    
    // Set the slave ID for this client
    const slaveId = this.slaveIdMap.get(clientId);
    if (slaveId === undefined) {
      throw new Error(`Client '${clientId}' not found in configuration`);
    }
    
    this.client.setID(slaveId);
    return this.client;
  }

  getClientIds(): string[] {
    return Array.from(this.slaveIdMap.keys());
  }

  async writeCoil(address: number, value: boolean, clientId: string = "default"): Promise<void> {
    const client = this.getClient(clientId);

    try {
      await client.writeCoil(address, value);
      logger.debug(`[${clientId}] Wrote coil ${address}: ${value}`);
    } catch (error) {
      logger.error(`[${clientId}] Failed to write coil ${address}:`, error);
      throw error;
    }
  }

  async writeRegister(address: number, value: number, clientId: string = "default"): Promise<void> {
    const client = this.getClient(clientId);

    try {
      await client.writeRegister(address, value);
      logger.debug(`[${clientId}] Wrote register ${address}: ${value}`);
    } catch (error) {
      logger.error(`[${clientId}] Failed to write register ${address}:`, error);
      throw error;
    }
  }

  async readCoils(address: number, length: number, clientId: string = "default"): Promise<boolean[]> {
    const client = this.getClient(clientId);

    try {
      const result = await client.readCoils(address, length);
      return result.data;
    } catch (error) {
      logger.error(`[${clientId}] Failed to read coils from ${address}:`, error);
      throw error;
    }
  }

  async readDiscreteInputs(address: number, length: number, clientId: string = "default"): Promise<boolean[]> {
    const client = this.getClient(clientId);

    try {
      const result = await client.readDiscreteInputs(address, length);
      return result.data;
    } catch (error) {
      logger.error(`[${clientId}] Failed to read discrete inputs from ${address}:`, error);
      throw error;
    }
  }

  async readHoldingRegisters(
    address: number,
    length: number,
    clientId: string = "default"
  ): Promise<number[]> {
    const client = this.getClient(clientId);

    try {
      const result = await client.readHoldingRegisters(address, length);
      return result.data;
    } catch (error) {
      logger.error(`[${clientId}] Failed to read holding registers from ${address}:`, error);
      throw error;
    }
  }

  isModbusConnected(clientId?: string): boolean {
    if (clientId) {
      return this.client !== null && this.slaveIdMap.has(clientId);
    }
    return this.client !== null;
  }
}

export const modbusService = new ModbusService();
