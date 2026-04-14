import { logger } from "../helper/logger";
import { configLoader } from "./configLoader";

export interface ModbusConnectionConfig {
  port: string;
  baudRate: number;
  dataBits: 7 | 8;
  stopBits: 1 | 2;
  parity: "none" | "even" | "odd";
  timeout: number;
}

export function getModbusConfig() {
  const config = configLoader.loadConfig();
  
  return {
    connection: {
      port: config.modbus.port,
      baudRate: config.modbus.baudRate || 9600,
      dataBits: (config.modbus.dataBits || 8) as 7 | 8,
      stopBits: (config.modbus.stopBits || 1) as 1 | 2,
      parity: (config.modbus.parity || "none") as "none" | "even" | "odd",
      timeout: config.modbus.timeout || 1000,
    },
    
    // Locker-specific addresses
    addresses: {
      lockControl: config.modbus.addresses?.lockControl ?? 0,
      lockStatus: config.modbus.addresses?.lockStatus ?? 1,
      doorSensor: config.modbus.addresses?.doorSensor ?? 2,
    },
  };
}

export function logCurrentModbusConfig(): void {
  const modbusConfig = getModbusConfig();

  logger.debug("Modbus configuration loaded:", {
    connection: modbusConfig.connection,
    addresses: modbusConfig.addresses,
  });
}
