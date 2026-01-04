import { logger } from "../helper/logger";
import { modbusService } from "../services/modbusService";
import { mqttService } from "../services/mqttService";
import { credentialsService } from "../services/credentialsService";

export class CommandHandler {
  private monitoringIntervals: Map<number, NodeJS.Timeout> = new Map();
  private readonly MONITORING_INTERVAL = 500; // 500ms polling interval
  private readonly COMPARTMENT_OPEN_DURATION = 200; // 200ms to keep relay on (time for lock to release)
  private readonly DEFAULT_CLIENT_ID = "locker2"; // Default Modbus client ID

  async handleOpenCompartment(compartmentID: number, clientId?: string): Promise<void> {
    const modbusClientId = clientId || this.DEFAULT_CLIENT_ID;
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
    // Waveshare relay uses 0-based addressing (0-7 for 8 channels)
    const relayAddress = compartmentID - 1; // Convert 1-based to 0-based
    
    if (relayAddress < 0 || relayAddress > 7) {
      throw new Error(`Invalid compartment number: ${compartmentID}. Must be between 1 and 8.`);
    }

    logger.info(`Activating relay ${relayAddress} (compartment ${compartmentID})`);
    
    // Turn relay ON (release lock)
    await modbusService.writeCoil(relayAddress, true, clientId);
    
    // Keep relay on for specified duration, then turn off
    setTimeout(async () => {
      try {
        await modbusService.writeCoil(relayAddress, false, clientId);
        logger.info(`Deactivated relay ${relayAddress} (compartment ${compartmentID})`);
      } catch (error) {
        logger.error(`Failed to deactivate relay ${relayAddress}:`, error);
      }
    }, this.COMPARTMENT_OPEN_DURATION);
  }

  private async startCoilMonitoring(compartmentID: number, clientId: string): Promise<void> {
    const relayAddress = compartmentID - 1; // Convert to 0-based
    const monitorKey = compartmentID; // Use 1-based for monitoring map
    
    // Stop any existing monitoring for this compartment
    this.stopCoilMonitoring(monitorKey);

    logger.info(`Starting relay monitoring for compartment ${compartmentID} (relay ${relayAddress})`);
    
    const monitorCoil = async () => {
      try {
        // Read relay status (function code 01)
        const relayStatus = await modbusService.readCoils(relayAddress, 1, clientId);
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
