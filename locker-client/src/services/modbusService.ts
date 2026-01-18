import ModbusRTU from "modbus-serial";
import { modbusConfig, ModbusClientConfig } from "../config/modbus";
import { logger } from "../helper/logger";

class ModbusService {
  private client: ModbusRTU | null = null;
  private clientConfigs: Map<string, ModbusClientConfig> = new Map();
  private slaveIdMap: Map<string, number> = new Map();
  private isConnecting: boolean = false;
  private reconnectAttempts: number = 0;
  private maxReconnectAttempts: number = 5;
  private reconnectDelay: number = 5000; // 5 seconds

  constructor() {
    // Store client configurations and build slave ID mapping
    modbusConfig.clients.forEach(config => {
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
      logger.info(`Configured slave IDs: ${Array.from(this.slaveIdMap.entries()).map(([id, slaveId]) => `${id}=${slaveId}`).join(', ')}`);
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
      logger.error(`Max Modbus reconnection attempts (${this.maxReconnectAttempts}) reached. Manual intervention required.`);
      return;
    }

    this.reconnectAttempts++;
    logger.info(`Attempting to reconnect Modbus (attempt ${this.reconnectAttempts}/${this.maxReconnectAttempts})...`);

    try {
      await this.connect();
      logger.info("Modbus reconnection successful");
    } catch (error) {
      logger.error(`Modbus reconnection attempt ${this.reconnectAttempts} failed:`, error);
      
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
}

export const modbusService = new ModbusService();
