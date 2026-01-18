import { logger } from "../helper/logger";
import { modbusService } from "./modbusService";
import { mqttService } from "./mqttService";
import { credentialsService } from "./credentialsService";

class CoilPollingService {
  private intervalId: NodeJS.Timeout | null = null;
  private pollingInterval: number = 5000; // 5 seconds
  private primaryClient: string = "locker2"; // Default to locker2 for Waveshare board
  private readonly NUM_CHANNELS = 8; // 8-channel relay board

  /**
   * Start polling relay and input status
   */
  start(): void {
    if (this.intervalId) {
      logger.warn("Coil polling service is already running");
      return;
    }

    // Get the first available client for monitoring
    const clientIds = modbusService.getClientIds();
    this.primaryClient = clientIds[0] || "locker2";

    logger.info(`Starting relay/input polling service with interval: ${this.pollingInterval / 1000}s for client: ${this.primaryClient}`);

    // Start polling at regular intervals
    this.intervalId = setInterval(() => {
      this.pollStatus();
    }, this.pollingInterval);

    // Poll immediately on start
    this.pollStatus();
  }

  /**
   * Stop polling
   */
  stop(): void {
    if (this.intervalId) {
      clearInterval(this.intervalId);
      this.intervalId = null;
      logger.info("Polling service stopped");
    }
  }

  /**
   * Poll relay and digital input status once
   */
  private async pollStatus(): Promise<void> {
    try {
      // Check if Modbus is connected before attempting to poll
      if (!modbusService.isModbusConnected()) {
        logger.warn("Modbus not connected, skipping poll and attempting reconnection...");
        await modbusService.ensureConnection();
        return;
      }

      // Read all 8 relay states (Function Code 01)
      const relayStates = await modbusService.readCoils(0x0000, this.NUM_CHANNELS, this.primaryClient);
      
      // Read all 8 digital input states (Function Code 02)  
      const inputStates = await modbusService.readDiscreteInputs(0x0000, this.NUM_CHANNELS, this.primaryClient);
      
      logger.debug(`[${this.primaryClient}] Relay states:`, relayStates);
      logger.debug(`[${this.primaryClient}] Input states (door sensors):`, inputStates);
      
      // Publish combined status to MQTT
      await this.publishStatus(relayStates, inputStates);
    } catch (error) {
      logger.error("Error polling relay/input status:", error);
      
      // If we get a port error, mark connection as lost and try to reconnect
      if (error instanceof Error && (error.message.includes("Port Not Open") || error.message.includes("ECONNREFUSED"))) {
        logger.warn("Port error detected, initiating reconnection...");
        modbusService.reconnect().catch(err => {
          logger.error("Failed to initiate reconnection:", err);
        });
      }
    }
  }

  /**
   * Publish status to MQTT
   */
  private async publishStatus(relayStates: boolean[], inputStates: boolean[]): Promise<void> {
    try {
      const credentials = credentialsService.getCredentials();
      if (!credentials || !credentials.username) {
        return;
      }

      const lockerUuid = credentials.username;
      const topic = `locker/${lockerUuid}/state`;
      
      const compartments = [];
      for (let i = 0; i < this.NUM_CHANNELS; i++) {
        compartments.push({
          compartment_id: i + 1,
          relay_state: relayStates[i] ? 'ON' : 'OFF',
          lock_state: relayStates[i] ? 'UNLOCKED' : 'LOCKED',
          door_sensor: inputStates[i] ? 'TRIGGERED' : 'IDLE',
          door_state: inputStates[i] ? 'OPEN' : 'CLOSED'
        });
      }

      const statusPayload = {
        event: 'status_update',
        data: {
          timestamp: new Date().toISOString(),
          compartments
        }
      };

      await mqttService.publish(topic, statusPayload);
    } catch (error) {
      logger.error("Failed to publish status to MQTT:", error);
    }
  }

  /**
   * Set the polling interval
   */
  setPollingInterval(intervalMs: number): void {
    this.pollingInterval = intervalMs;
    
    // Restart if already running
    if (this.intervalId) {
      this.stop();
      this.start();
    }
  }
}

export const coilPollingService = new CoilPollingService();
