import ModbusRTU from "modbus-serial";
import { modbusConfig } from "../config/modbus";
import { logger } from "../helper/logger";

class ModbusService {
  private client: ModbusRTU;
  private isConnected = false;

  constructor() {
    this.client = new ModbusRTU();
  }

  async connect(): Promise<void> {
    try {
      await this.client.connectRTUBuffered(modbusConfig.port, {
        baudRate: modbusConfig.baudRate,
        dataBits: modbusConfig.dataBits,
        stopBits: modbusConfig.stopBits,
        parity: modbusConfig.parity,
      });

      this.client.setID(modbusConfig.slaveId);
      this.client.setTimeout(modbusConfig.timeout);

      this.isConnected = true;
      logger.info("Modbus RTU connection established");
    } catch (error) {
      logger.error("Failed to connect to Modbus RTU:", error);
      throw error;
    }
  }

  async disconnect(): Promise<void> {
    if (this.isConnected) {
      this.client.close(() => {
        this.isConnected = false;
        logger.info("Modbus RTU connection closed");
      });
    }
  }

  async writeCoil(address: number, value: boolean): Promise<void> {
    if (!this.isConnected) {
      throw new Error("Modbus client is not connected");
    }

    try {
      await this.client.writeCoil(address, value);
      logger.debug(`Wrote coil ${address}: ${value}`);
    } catch (error) {
      logger.error(`Failed to write coil ${address}:`, error);
      throw error;
    }
  }

  async writeRegister(address: number, value: number): Promise<void> {
    if (!this.isConnected) {
      throw new Error("Modbus client is not connected");
    }

    try {
      await this.client.writeRegister(address, value);
      logger.debug(`Wrote register ${address}: ${value}`);
    } catch (error) {
      logger.error(`Failed to write register ${address}:`, error);
      throw error;
    }
  }

  async readCoils(address: number, length: number): Promise<boolean[]> {
    if (!this.isConnected) {
      throw new Error("Modbus client is not connected");
    }

    try {
      const result = await this.client.readCoils(address, length);
      return result.data;
    } catch (error) {
      logger.error(`Failed to read coils from ${address}:`, error);
      throw error;
    }
  }

  async readHoldingRegisters(
    address: number,
    length: number
  ): Promise<number[]> {
    if (!this.isConnected) {
      throw new Error("Modbus client is not connected");
    }

    try {
      const result = await this.client.readHoldingRegisters(address, length);
      return result.data;
    } catch (error) {
      logger.error(`Failed to read holding registers from ${address}:`, error);
      throw error;
    }
  }

  isModbusConnected(): boolean {
    return this.isConnected;
  }
}

export const modbusService = new ModbusService();
