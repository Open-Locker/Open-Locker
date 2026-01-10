import { logger } from "../helper/logger";
import { modbusService } from "../services/modbusService";
import { mqttService } from "../services/mqttService";
import { credentialsService } from "../services/credentialsService";
import { configLoader } from "../config/configLoader";

export class CommandHandler {
  private monitoringIntervals: Map<number, NodeJS.Timeout> = new Map();
  private readonly MONITORING_INTERVAL = 500; // 500ms polling interval
  private readonly COMPARTMENT_OPEN_DURATION = 200; // 200ms to keep relay on (time for lock to release)

  async handleOpenCompartment(compartmentID: number, clientId?: string): Promise<void> {
    // Use provided clientId or default to the first available client
    let modbusClientId = clientId;
    if (!modbusClientId) {
      const availableClients = modbusService.getClientIds();
      if (availableClients.length === 0) {
        throw new Error("No Modbus clients available");
      }
      modbusClientId = availableClients[0];
      logger.debug(`No client ID specified, using first available: ${modbusClientId}`);
    }
    
    logger.info(`Opening compartment ${compartmentID} on client ${modbusClientId}`);

    try {
      await this.openCompartment(compartmentID, modbusClientId);
      await this.startCoilMonitoring(compartmentID, modbusClientId);
    } catch (error) {
      logger.error("Failed to execute command:", error);
      await this.reportError(error);
      throw error;
    }
  }

  private async openCompartment(compartmentID: number, clientId: string) {
    // Get compartment configuration from config file
    const compartmentConfig = configLoader.getCompartmentConfig(compartmentID);
    
    if (!compartmentConfig) {
      // Fallback to legacy mode if no compartment config
      logger.warn(`No configuration found for compartment ${compartmentID}, using legacy addressing`);
      const relayAddress = compartmentID - 1; // Convert 1-based to 0-based
      
      if (relayAddress < 0 || relayAddress > 7) {
        throw new Error(`Invalid compartment number: ${compartmentID}. Must be between 1 and 8.`);
      }
      
      logger.info(`Activating relay ${relayAddress} (compartment ${compartmentID})`);  
      await modbusService.writeCoil(relayAddress, true, clientId);
      
      setTimeout(async () => {
        try {
          await modbusService.writeCoil(relayAddress, false, clientId);
          logger.info(`Deactivated relay ${relayAddress} (compartment ${compartmentID})`);
        } catch (error) {
          logger.error(`Failed to deactivate relay ${relayAddress}:`, error);
        }
      }, this.COMPARTMENT_OPEN_DURATION);
      return;
    }
    
    // Use compartment config
    const relayAddress = compartmentConfig.address;
    const targetSlaveId = compartmentConfig.slaveId;
    
    // Find the client ID for this slave
    const config = configLoader.getConfig();
    const targetClient = config?.modbus.clients.find(c => c.slaveId === targetSlaveId);
    
    if (!targetClient) {
      throw new Error(`No Modbus client configured for slave ID ${targetSlaveId}`);
    }
    
    logger.info(`Activating relay ${relayAddress} on slave ${targetSlaveId} (compartment ${compartmentID})`);
    
    // Turn relay ON (release lock) - use target client from compartment config
    await modbusService.writeCoil(relayAddress, true, targetClient.id);
    
    // Keep relay on for specified duration, then turn off
    setTimeout(async () => {
      try {
        await modbusService.writeCoil(relayAddress, false, targetClient.id);
        logger.info(`Deactivated relay ${relayAddress} on slave ${targetSlaveId} (compartment ${compartmentID})`);
      } catch (error) {
        logger.error(`Failed to deactivate relay ${relayAddress} on slave ${targetSlaveId}:`, error);
      }
    }, this.COMPARTMENT_OPEN_DURATION);
  }

  private async startCoilMonitoring(compartmentID: number, clientId: string): Promise<void> {
    // Get compartment configuration from config file
    const compartmentConfig = configLoader.getCompartmentConfig(compartmentID);
    
    let relayAddress: number;
    let targetClientId: string;
    
    if (!compartmentConfig) {
      // Fallback to legacy mode if no compartment config
      logger.warn(`No configuration found for compartment ${compartmentID}, using legacy addressing for monitoring`);
      relayAddress = compartmentID - 1; // Convert to 0-based
      targetClientId = clientId;
    } else {
      // Use compartment config
      relayAddress = compartmentConfig.address;
      const targetSlaveId = compartmentConfig.slaveId;
      
      // Find the client ID for this slave
      const config = configLoader.getConfig();
      const targetClient = config?.modbus.clients.find(c => c.slaveId === targetSlaveId);
      
      if (!targetClient) {
        throw new Error(`No Modbus client configured for slave ID ${targetSlaveId}`);
      }
      
      targetClientId = targetClient.id;
    }
    
    const monitorKey = compartmentID; // Use 1-based for monitoring map
    
    // Stop any existing monitoring for this compartment
    this.stopCoilMonitoring(monitorKey);

    logger.info(`Starting relay monitoring for compartment ${compartmentID} (relay ${relayAddress} on client ${targetClientId})`);
    
    const monitorCoil = async () => {
      try {
        // Read relay status (function code 01)
        const relayStatus = await modbusService.readCoils(relayAddress, 1, targetClientId);
        const isRelayOn = relayStatus[0];
        
        logger.debug(`Compartment ${compartmentID} relay status: ${isRelayOn ? 'ON (unlocked)' : 'OFF (locked)'}`);

        // Publish status to MQTT
        await this.publishCoilStatus(compartmentID, isRelayOn);

        // If relay is off (lock is engaged), stop monitoring
        if (!isRelayOn) {
          logger.info(`Compartment ${compartmentID} is now locked. Stopping monitoring.`);
          this.stopCoilMonitoring(monitorKey);
        }
      } catch (error) {
        logger.error(`Error monitoring compartment ${compartmentID}:`, error);
        this.stopCoilMonitoring(monitorKey);
        await this.reportError(error);
      }
    };

    // Start monitoring immediately
    await monitorCoil();
    
    // Continue monitoring at regular intervals if relay is still on
    if (!this.monitoringIntervals.has(monitorKey)) {
      const interval = setInterval(monitorCoil, this.MONITORING_INTERVAL);
      this.monitoringIntervals.set(monitorKey, interval);
    }
  }

  private stopCoilMonitoring(compartmentID: number): void {
    const interval = this.monitoringIntervals.get(compartmentID);
    if (interval) {
      clearInterval(interval);
      this.monitoringIntervals.delete(compartmentID);
      logger.info(`Stopped coil monitoring for compartment ${compartmentID}`);
    }
  }

  public stopAllMonitoring(): void {
    logger.info("Stopping all compartment monitoring");
    this.monitoringIntervals.forEach((interval, compartmentID) => {
      clearInterval(interval);
      logger.info(`Stopped monitoring for compartment ${compartmentID}`);
    });
    this.monitoringIntervals.clear();
  }

  private async publishCoilStatus(compartmentID: number, isRelayOn: boolean): Promise<void> {
    try {
      const credentials = credentialsService.getCredentials();
      if (!credentials || !credentials.username) {
        logger.warn("No credentials available, skipping MQTT status publish");
        return;
      }

      const lockerUuid = credentials.username;
      const topic = `locker/${lockerUuid}/status`;
      
      const statusPayload = {
        compartment_id: compartmentID,
        relay_state: isRelayOn ? 'ON' : 'OFF',
        lock_state: isRelayOn ? 'UNLOCKED' : 'LOCKED',
        timestamp: new Date().toISOString()
      };

      await mqttService.publish(topic, statusPayload);
      logger.debug(`Published relay status to ${topic}:`, statusPayload);
    } catch (error) {
      logger.error("Failed to publish relay status to MQTT:", error);
      // Don't throw error - we don't want MQTT failures to stop monitoring
    }
  }

  private async reportError(error: any): Promise<void> {
    logger.error("Error reported:", error.message);
  }
}

export const commandHandler = new CommandHandler();
