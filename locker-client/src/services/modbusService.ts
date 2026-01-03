import ModbusRTU from "modbus-serial";
import { modbusConfig, ModbusClientConfig } from "../config/modbus";
import { logger } from "../helper/logger";

class ModbusService {
  private clients: Map<string, ModbusRTU> = new Map();
  private clientConfigs: Map<string, ModbusClientConfig> = new Map();

  constructor() {
    // Store client configurations
    modbusConfig.clients.forEach(config => {
      this.clientConfigs.set(config.id, config);
    });
  }

  async connect(): Promise<void> {
    const connectionPromises = Array.from(this.clientConfigs.entries()).map(
      async ([id, config]) => {
        try {
          const client = new ModbusRTU();
          
          await client.connectRTUBuffered(config.port, {
            baudRate: config.baudRate,
            dataBits: config.dataBits,
            stopBits: config.stopBits,
            parity: config.parity,
          });

          client.setID(config.slaveId);
          client.setTimeout(config.timeout);

          this.clients.set(id, client);
          logger.info(`Modbus RTU client '${id}' connected to ${config.port}`);
        } catch (error) {
          logger.error(`Failed to connect Modbus client '${id}':`, error);
          throw error;
        }
      }
    );

    await Promise.all(connectionPromises);
    logger.info(`All Modbus RTU connections established (${this.clients.size} clients)`);
  }

  async disconnect(): Promise<void> {
    const disconnectPromises = Array.from(this.clients.entries()).map(
      ([id, client]) =>
        new Promise<void>((resolve) => {
          client.close(() => {
            logger.info(`Modbus RTU client '${id}' connection closed`);
            resolve();
          });
        })
    );

    await Promise.all(disconnectPromises);
    this.clients.clear();
    logger.info("All Modbus RTU connections closed");
  }

  getClient(clientId: string = "default"): ModbusRTU {
    const client = this.clients.get(clientId);
    if (!client) {
      throw new Error(`Modbus client '${clientId}' not found`);
    }
    return client;
  }

  getClientIds(): string[] {
    return Array.from(this.clients.keys());
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
      return this.clients.has(clientId);
    }
    return this.clients.size > 0;
  }
}

export const modbusService = new ModbusService();
