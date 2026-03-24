import { logger } from "../helper/logger";
import { modbusService } from "../services/modbusService";
import { mqttService } from "../services/mqttService";
import { credentialsService } from "../services/credentialsService";
import { configLoader } from "../config/configLoader";

export class CommandHandler {
  private monitoringIntervals: Map<number, NodeJS.Timeout> = new Map();
  private readonly MONITORING_INTERVAL = 500; // 500ms polling interval
  private readonly DEFAULT_FLASH_DURATION_MS = 200;

  async handleOpenCompartment(
    compartmentID: number,
    clientId?: string,
  ): Promise<void> {
    // Check Modbus connection before attempting operation
    if (!modbusService.isModbusConnected()) {
      logger.warn(
        "Modbus not connected, attempting to establish connection...",
      );
      const connected = await modbusService.ensureConnection();
      if (!connected) {
        throw new Error(
          "Cannot open compartment: Modbus connection unavailable",
        );
      }
    }

    // Use provided clientId or default to the first available client
    let modbusClientId = clientId;
    if (!modbusClientId) {
      const availableClients = modbusService.getClientIds();
      if (availableClients.length === 0) {
        throw new Error("No Modbus clients available");
      }
      modbusClientId = availableClients[0];
      logger.debug(
        `No client ID specified, using first available: ${modbusClientId}`,
      );
    }

    logger.info(
      `Opening compartment ${compartmentID} on client ${modbusClientId}`,
    );

    try {
      await this.openCompartment(compartmentID, modbusClientId);
      await this.startCoilMonitoring(compartmentID, modbusClientId);
    } catch (error) {
      logger.error("Failed to execute command:", error);

      // If we get a port error, try to reconnect
      if (
        error instanceof Error &&
        (error.message.includes("Port Not Open") ||
          error.message.includes("ECONNREFUSED"))
      ) {
        logger.warn("Port error detected, initiating reconnection...");
        modbusService.reconnect().catch((err) => {
          logger.error("Failed to initiate reconnection:", err);
        });
      }

      await this.reportError(error);
      throw error;
    }
  }

  private async openCompartment(compartmentID: number, clientId: string) {
    const { relayAddress, targetClientId, targetSlaveId } =
      this.resolveCompartmentTarget(compartmentID, clientId);
    const flashDurationMs = this.getFlashDurationMs();

    logger.info(
      `Triggering hardware flash ON for relay ${relayAddress} on slave ${targetSlaveId} (compartment ${compartmentID}) for ${flashDurationMs}ms`,
    );

    await modbusService.flashRelayOn(
      relayAddress,
      flashDurationMs,
      targetClientId,
    );
  }

  private async startCoilMonitoring(
    compartmentID: number,
    clientId: string,
  ): Promise<void> {
    const { relayAddress, targetClientId } = this.resolveCompartmentTarget(
      compartmentID,
      clientId,
    );

    const monitorKey = compartmentID; // Use 1-based for monitoring map

    // Stop any existing monitoring for this compartment
    this.stopCoilMonitoring(monitorKey);

    logger.info(
      `Starting relay monitoring for compartment ${compartmentID} (relay ${relayAddress} on client ${targetClientId})`,
    );

    const monitorCoil = async () => {
      try {
        // Check connection before monitoring
        if (!modbusService.isModbusConnected()) {
          logger.warn(
            `Connection lost while monitoring compartment ${compartmentID}, stopping monitoring`,
          );
          this.stopCoilMonitoring(monitorKey);
          return;
        }

        // Read relay status (function code 01)
        const relayStatus = await modbusService.readCoils(
          relayAddress,
          1,
          targetClientId,
        );
        const isRelayOn = relayStatus[0];

        logger.debug(
          `Compartment ${compartmentID} relay status: ${
            isRelayOn ? "ON (unlocked)" : "OFF (locked)"
          }`,
        );

        // Publish status to MQTT
        await this.publishCoilStatus(compartmentID, isRelayOn);

        // If relay is off (lock is engaged), stop monitoring
        if (!isRelayOn) {
          logger.info(
            `Compartment ${compartmentID} is now locked. Stopping monitoring.`,
          );
          this.stopCoilMonitoring(monitorKey);
        }
      } catch (error) {
        logger.error(`Error monitoring compartment ${compartmentID}:`, error);

        // If we get a port error, stop monitoring and try to reconnect
        if (
          error instanceof Error &&
          (error.message.includes("Port Not Open") ||
            error.message.includes("ECONNREFUSED"))
        ) {
          logger.warn(
            "Port error detected during monitoring, initiating reconnection...",
          );
          modbusService.reconnect().catch((err) => {
            logger.error("Failed to initiate reconnection:", err);
          });
        }

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

  private async publishCoilStatus(
    compartmentID: number,
    isRelayOn: boolean,
  ): Promise<void> {
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
        relay_state: isRelayOn ? "ON" : "OFF",
        lock_state: isRelayOn ? "UNLOCKED" : "LOCKED",
        timestamp: new Date().toISOString(),
      };

      await mqttService.publish(topic, statusPayload);
      logger.debug(`Published relay status to ${topic}:`, statusPayload);
    } catch (error) {
      logger.error("Failed to publish relay status to MQTT:", error);
      // Don't throw error - we don't want MQTT failures to stop monitoring
    }
  }

  private getFlashDurationMs(): number {
    const configuredDuration = configLoader.getConfig()?.modbus.flashDurationMs;

    if (configuredDuration === undefined) {
      return this.DEFAULT_FLASH_DURATION_MS;
    }

    return configuredDuration;
  }

  private resolveCompartmentTarget(
    compartmentID: number,
    fallbackClientId: string,
  ): {
    relayAddress: number;
    targetClientId: string;
    targetSlaveId: number;
  } {
    const compartmentConfig = configLoader.getCompartmentConfig(compartmentID);

    if (!compartmentConfig) {
      logger.warn(
        `No configuration found for compartment ${compartmentID}, using legacy addressing`,
      );

      const relayAddress = compartmentID - 1;
      if (relayAddress < 0 || relayAddress > 7) {
        throw new Error(
          `Invalid compartment number: ${compartmentID}. Must be between 1 and 8.`,
        );
      }

      const config = configLoader.getConfig();
      const fallbackClient = config?.modbus.clients.find((client) =>
        client.id === fallbackClientId
      );

      if (!fallbackClient) {
        throw new Error(
          `No Modbus client configured for client ID ${fallbackClientId}`,
        );
      }

      return {
        relayAddress,
        targetClientId: fallbackClientId,
        targetSlaveId: fallbackClient.slaveId,
      };
    }

    const config = configLoader.getConfig();
    const targetClient = config?.modbus.clients.find((client) =>
      client.slaveId === compartmentConfig.slaveId
    );

    if (!targetClient) {
      throw new Error(
        `No Modbus client configured for slave ID ${compartmentConfig.slaveId}`,
      );
    }

    return {
      relayAddress: compartmentConfig.address,
      targetClientId: targetClient.id,
      targetSlaveId: compartmentConfig.slaveId,
    };
  }

  private async reportError(error: any): Promise<void> {
    logger.error("Error reported:", error.message);
  }
}

export const commandHandler = new CommandHandler();
