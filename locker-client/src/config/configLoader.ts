import fs from "fs";
import { load } from "js-yaml";
import { logger } from "../helper/logger";
import { loadRuntimeConfigOverlay, mergeRuntimeConfig } from "./runtimeConfig";
import { CONFIG_FILE } from "./paths";

export interface CompartmentConfig {
  compartment_number: number;
  slaveId: number;
  address: number;
}

export interface LockerConfig {
  mqtt?: {
    heartbeatInterval?: number; // in seconds
    /** false = persistent session (MQTT clean_session=false); aligns with broker QoS buffering */
    cleanSession?: boolean;
    keepaliveSeconds?: number;
    reconnectPeriodMs?: number;
    connectTimeoutMs?: number;
    /** 0 = unlimited reconnect attempts (recommended for cabinets) */
    maxReconnectAttempts?: number;
  };
  modbus: {
    port: string; // Main MODBUS_PORT
    flashDurationMs?: number;
    baudRate?: number;
    dataBits?: 7 | 8;
    stopBits?: 1 | 2;
    parity?: "none" | "even" | "odd";
    timeout?: number;
    addresses?: {
      lockControl?: number;
      lockStatus?: number;
      doorSensor?: number;
    };
  };
  compartments?: CompartmentConfig[];
  logging?: {
    level?: string;
  };
}

class ConfigLoader {
  private config: LockerConfig | null = null;
  private hasExplicitRuntimeCompartments = false;

  public loadConfig(): LockerConfig {
    if (this.config) {
      return this.config;
    }

    try {
      if (!fs.existsSync(CONFIG_FILE)) {
        throw new Error(`Configuration file not found: ${CONFIG_FILE}`);
      }

      const fileContents = fs.readFileSync(CONFIG_FILE, "utf8");
      const parsedConfig = (load(fileContents) as LockerConfig) ?? {};
      parsedConfig.mqtt = parsedConfig.mqtt ?? {};

      // Validate required fields
      if (!parsedConfig.modbus?.port) {
        throw new Error("modbus.port is required in configuration");
      }

      const runtimeOverlay = loadRuntimeConfigOverlay();
      this.hasExplicitRuntimeCompartments =
        runtimeOverlay?.compartments !== undefined;
      this.config = mergeRuntimeConfig(parsedConfig, runtimeOverlay);
      logger.info("Configuration loaded successfully from " + CONFIG_FILE);
      logger.info(`Modbus Port: ${this.config.modbus.port}`);
      logger.info(
        `Modbus serial defaults: baudRate=${this.config.modbus.baudRate || 9600}, dataBits=${this.config.modbus.dataBits || 8}, stopBits=${this.config.modbus.stopBits || 1}, parity=${this.config.modbus.parity || "none"}, timeout=${this.config.modbus.timeout || 1000}`,
      );

      // Log compartment configuration
      if (this.config.compartments && this.config.compartments.length > 0) {
        logger.info(
          `Number of compartments configured: ${this.config.compartments.length}`,
        );
        this.config.compartments.forEach((compartment) => {
          logger.info(
            `Compartment ${compartment.compartment_number}: SlaveID=${compartment.slaveId}, Address=${compartment.address}`,
          );
        });
      } else {
        logger.warn(
          "No compartments configured. Using legacy addressing mode.",
        );
      }

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
    this.hasExplicitRuntimeCompartments = false;
    return this.loadConfig();
  }

  public hasExplicitRuntimeCompartmentsConfig(): boolean {
    if (!this.config) {
      this.loadConfig();
    }

    return this.hasExplicitRuntimeCompartments;
  }

  public getCompartmentConfig(compartmentId: number): CompartmentConfig | null {
    if (!this.config?.compartments) {
      return null;
    }

    const compartment = this.config.compartments.find(
      (c) => c.compartment_number === compartmentId,
    );
    return compartment || null;
  }
}

export const configLoader = new ConfigLoader();
