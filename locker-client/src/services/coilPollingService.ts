import { configLoader } from "../config/configLoader";
import { logger } from "../helper/logger";
import { modbusService } from "./modbusService";
import { mqttService } from "./mqttService";
import { credentialsService } from "./credentialsService";

interface PollingTarget {
  clientId: string;
  slaveId: number;
  clientIndex: number;
}

interface PolledClientSnapshot extends PollingTarget {
  relayStates: boolean[];
  inputStates: boolean[];
}

interface CompartmentStatusPayload {
  compartment_id: number;
  relay_state: "ON" | "OFF";
  lock_state: "UNLOCKED" | "LOCKED";
  door_sensor: "TRIGGERED" | "IDLE";
  door_state: "OPEN" | "CLOSED";
}

class CoilPollingService {
  private intervalId: NodeJS.Timeout | null = null;
  private pollingInterval: number = 5000; // 5 seconds
  private readonly NUM_CHANNELS = 8; // 8-channel relay board
  private hasWarnedAboutLegacyMultiBoardMapping: boolean = false;

  /**
   * Start polling relay and input status
   */
  start(): void {
    if (this.intervalId) {
      logger.warn("Coil polling service is already running");
      return;
    }

    const pollingTargets = this.getPollingTargets();
    if (pollingTargets.length === 0) {
      logger.warn("No Modbus clients available, skipping polling startup");
      return;
    }

    logger.info(
      `Starting relay/input polling service with interval: ${this.pollingInterval / 1000}s for clients: ${pollingTargets.map((target) => target.clientId).join(", ")}`,
    );

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
    // Check if Modbus is connected before attempting to poll
    if (!modbusService.isModbusConnected()) {
      logger.warn(
        "Modbus not connected, skipping poll and attempting reconnection...",
      );
      await modbusService.ensureConnection();
      return;
    }

    const pollingTargets = this.getPollingTargets();
    if (pollingTargets.length === 0) {
      logger.warn("No Modbus clients configured for polling");
      return;
    }

    const snapshots: PolledClientSnapshot[] = [];

    for (const target of pollingTargets) {
      try {
        const relayStates = await modbusService.readCoils(
          0x0000,
          this.NUM_CHANNELS,
          target.clientId,
        );

        const inputStates = await modbusService.readDiscreteInputs(
          0x0000,
          this.NUM_CHANNELS,
          target.clientId,
        );

        logger.debug(`[${target.clientId}] Relay states:`, relayStates);
        logger.debug(
          `[${target.clientId}] Input states (door sensors):`,
          inputStates,
        );

        snapshots.push({
          ...target,
          relayStates,
          inputStates,
        });
      } catch (error) {
        logger.error(
          `[${target.clientId}] Error polling relay/input status:`,
          error,
        );

        // Reconnect only on transport failures. A board timeout should not
        // reset polling for other boards on the same RS485 bus.
        if (
          error instanceof Error &&
          (error.message.includes("Port Not Open") ||
            error.message.includes("ECONNREFUSED"))
        ) {
          logger.warn("Port error detected, initiating reconnection...");
          modbusService.reconnect().catch((reconnectError) => {
            logger.error("Failed to initiate reconnection:", reconnectError);
          });
        }
      }
    }

    if (snapshots.length === 0) {
      logger.warn("No reachable Modbus boards responded during polling cycle");
      return;
    }

    // Publish combined status across all reachable boards.
    await this.publishStatus(snapshots);
  }

  /**
   * Publish status to MQTT
   */
  private async publishStatus(
    snapshots: PolledClientSnapshot[],
  ): Promise<void> {
    try {
      const credentials = credentialsService.getCredentials();
      if (!credentials || !credentials.username) {
        return;
      }

      const lockerUuid = credentials.username;
      const topic = `locker/${lockerUuid}/state`;
      const compartments = this.buildCompartmentPayload(snapshots);

      const statusPayload = {
        event: "status_update",
        data: {
          timestamp: new Date().toISOString(),
          compartments,
        },
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

  private getPollingTargets(): PollingTarget[] {
    const config = configLoader.getConfig();
    const configuredClients = config?.modbus.clients ?? [];

    return modbusService.getClientIds().flatMap((clientId, clientIndex) => {
      const configuredClient = configuredClients.find((client) =>
        client.id === clientId
      );

      if (!configuredClient) {
        logger.warn(
          `Skipping polling for client ${clientId}: no matching Modbus client configuration found`,
        );
        return [];
      }

      return [{
        clientId,
        slaveId: configuredClient.slaveId,
        clientIndex,
      }];
    });
  }

  private buildCompartmentPayload(
    snapshots: PolledClientSnapshot[],
  ): CompartmentStatusPayload[] {
    const configuredCompartments = configLoader.getConfig()?.compartments;

    if (!configuredCompartments || configuredCompartments.length === 0) {
      if (snapshots.length > 1 && !this.hasWarnedAboutLegacyMultiBoardMapping) {
        logger.warn(
          "Polling multiple Modbus boards without compartment mapping. Falling back to derived compartment IDs by client order.",
        );
        this.hasWarnedAboutLegacyMultiBoardMapping = true;
      }

      return snapshots
        .flatMap((snapshot) =>
          snapshot.relayStates.map((relayState, address) =>
            this.toCompartmentStatus(
              snapshot.clientIndex * this.NUM_CHANNELS + address + 1,
              relayState,
              snapshot.inputStates[address] ?? false,
            ),
          )
        )
        .sort((left, right) => left.compartment_id - right.compartment_id);
    }

    const compartments: CompartmentStatusPayload[] = [];

    for (const snapshot of snapshots) {
      for (const compartment of configuredCompartments) {
        if (compartment.slaveId !== snapshot.slaveId) {
          continue;
        }

        if (
          compartment.address < 0 ||
          compartment.address >= this.NUM_CHANNELS
        ) {
          logger.warn(
            `Skipping compartment ${compartment.id}: address ${compartment.address} is outside the supported polling range`,
          );
          continue;
        }

        compartments.push(
          this.toCompartmentStatus(
            compartment.id,
            snapshot.relayStates[compartment.address] ?? false,
            snapshot.inputStates[compartment.address] ?? false,
          ),
        );
      }
    }

    return compartments.sort(
      (left, right) => left.compartment_id - right.compartment_id,
    );
  }

  private toCompartmentStatus(
    compartmentId: number,
    relayState: boolean,
    inputState: boolean,
  ): CompartmentStatusPayload {
    return {
      compartment_id: compartmentId,
      relay_state: relayState ? "ON" : "OFF",
      lock_state: relayState ? "UNLOCKED" : "LOCKED",
      door_sensor: inputState ? "TRIGGERED" : "IDLE",
      door_state: inputState ? "OPEN" : "CLOSED",
    };
  }
}

export const coilPollingService = new CoilPollingService();
