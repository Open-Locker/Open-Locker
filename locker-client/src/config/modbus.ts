import { logger } from "../helper/logger";
import { configLoader, ModbusClientConfig as ConfigModbusClient } from "./configLoader";

export interface ModbusClientConfig {
  id: string;
  port: string;
  baudRate: number;
  dataBits: 7 | 8;
  stopBits: 1 | 2;
  parity: "none" | "even" | "odd";
  slaveId: number;
  timeout: number;
}

// Parse Modbus clients from configuration
function parseModbusClients(): ModbusClientConfig[] {
  const config = configLoader.loadConfig();
  const modbusPort = config.modbus.port;
  
  return config.modbus.clients.map((client: ConfigModbusClient) => ({
    id: client.id,
    port: modbusPort, // Use the main MODBUS_PORT from config
    baudRate: client.baudRate || 9600,
    dataBits: (client.dataBits || 8) as 7 | 8,
    stopBits: (client.stopBits || 1) as 1 | 2,
    parity: (client.parity || "none") as "none" | "even" | "odd",
    slaveId: client.slaveId,
    timeout: client.timeout || 1000,
  }));
}

function getModbusConfig() {
  const config = configLoader.loadConfig();
  
  return {
    clients: parseModbusClients(),
    
    // Locker-specific addresses
    addresses: {
      lockControl: config.modbus.addresses?.lockControl ?? 0,
      lockStatus: config.modbus.addresses?.lockStatus ?? 1,
      doorSensor: config.modbus.addresses?.doorSensor ?? 2,
    },
  };
}

export const modbusConfig = getModbusConfig();

logger.debug("Modbus configuration loaded:", {
  clientCount: modbusConfig.clients.length,
  clients: modbusConfig.clients,
  addresses: modbusConfig.addresses,
});
