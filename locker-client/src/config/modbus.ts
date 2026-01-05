import dotenv from "dotenv";
import { logger } from "../helper/logger";

dotenv.config();

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

// Parse multiple Modbus clients from environment
function parseModbusClients(): ModbusClientConfig[] {
  const clientsJson = process.env.MODBUS_CLIENTS;
  
  if (clientsJson) {
    try {
      const clients = JSON.parse(clientsJson);
      return clients.map((client: any) => ({
        id: client.id,
        port: client.port,
        baudRate: client.baudRate || 9600,
        dataBits: (client.dataBits || 8) as 7 | 8,
        stopBits: (client.stopBits || 1) as 1 | 2,
        parity: (client.parity || "none") as "none" | "even" | "odd",
        slaveId: client.slaveId || 1,
        timeout: client.timeout || 1000,
      }));
    } catch (error) {
      console.error("Failed to parse MODBUS_CLIENTS:", error);
    }
  }
  
  // Fallback to single client configuration from individual env vars
  return [
    {
      id: "default",
      port: process.env.MODBUS_PORT || "/dev/ttyUSB0",
      baudRate: parseInt(process.env.MODBUS_BAUD_RATE || "9600"),
      dataBits: parseInt(process.env.MODBUS_DATA_BITS || "8") as 7 | 8,
      stopBits: parseInt(process.env.MODBUS_STOP_BITS || "1") as 1 | 2,
      parity: (process.env.MODBUS_PARITY || "none") as "none" | "even" | "odd",
      slaveId: parseInt(process.env.MODBUS_SLAVE_ID || "1"),
      timeout: parseInt(process.env.MODBUS_TIMEOUT || "1000"),
    },
  ];
}

export const modbusConfig = {
  clients: parseModbusClients(),
  
  // Locker-specific addresses
  addresses: {
    lockControl: parseInt(process.env.MODBUS_LOCK_CONTROL_ADDR || "0"),
    lockStatus: parseInt(process.env.MODBUS_LOCK_STATUS_ADDR || "1"),
    doorSensor: parseInt(process.env.MODBUS_DOOR_SENSOR_ADDR || "2"),
  },
};

logger.debug("Modbus configuration loaded:", {
  clientCount: modbusConfig.clients.length,
  clients: modbusConfig.clients,
  addresses: modbusConfig.addresses,
});
