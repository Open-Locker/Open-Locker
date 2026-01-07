import fs from "fs";
import yaml from "js-yaml";
import { logger } from "../helper/logger";
import { CONFIG_FILE } from "./paths";

export interface ModbusClientConfig {
  id: string;
  baudRate?: number;
  dataBits?: 7 | 8;
  stopBits?: 1 | 2;
  parity?: "none" | "even" | "odd";
  slaveId: number;
  timeout?: number;
}

export interface LockerConfig {
  mqtt: {
    brokerUrl: string;
    defaultUsername: string;
    defaultPassword: string;
    heartbeatInterval?: number; // in seconds
  };
  modbus: {
    port: string; // Main MODBUS_PORT
    clients: ModbusClientConfig[];
    addresses?: {
      lockControl?: number;
      lockStatus?: number;
      doorSensor?: number;
    };
  };
  logging?: {
    level?: string;
  };
}

class ConfigLoader {
  private config: LockerConfig | null = null;

  public loadConfig(): LockerConfig {
    if (this.config) {
      return this.config;
    }

    try {
      if (!fs.existsSync(CONFIG_FILE)) {
        throw new Error(`Configuration file not found: ${CONFIG_FILE}`);
      }

      const fileContents = fs.readFileSync(CONFIG_FILE, "utf8");
      const parsedConfig = yaml.load(fileContents) as LockerConfig;

      // Validate required fields
      if (!parsedConfig.mqtt?.brokerUrl) {
        throw new Error("mqtt.brokerUrl is required in configuration");
      }

      if (!parsedConfig.modbus?.port) {
        throw new Error("modbus.port is required in configuration");
      }

      if (!parsedConfig.modbus?.clients || !Array.isArray(parsedConfig.modbus.clients)) {
        throw new Error("modbus.clients must be an array in configuration");
      }

      this.config = parsedConfig;
      logger.info("Configuration loaded successfully from " + CONFIG_FILE);
      logger.info(`MQTT Broker: ${parsedConfig.mqtt.brokerUrl}`);
      logger.info(`Modbus Port: ${parsedConfig.modbus.port}`);
      logger.info(`Number of locker clients configured: ${parsedConfig.modbus.clients.length}`);
      
      // Log details for each locker client
      parsedConfig.modbus.clients.forEach((client, index) => {
        logger.info(`Locker ${index + 1}: ID=${client.id}, SlaveID=${client.slaveId}, BaudRate=${client.baudRate || 9600}`);
      });
      
      return this.config;
    } catch (error) {
      logger.error("Failed to load configuration:", error);
      throw error;
    }
  }

  public getConfig(): LockerConfig | null {
    return this.config;
  }

  public reloadConfig(): LockerConfig {
    this.config = null;
    return this.loadConfig();
  }
}

export const configLoader = new ConfigLoader();
