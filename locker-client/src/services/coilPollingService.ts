import { configLoader } from "../config/configLoader";
import { logger } from "../helper/logger";
import { modbusService } from "./modbusService";
import { mqttService } from "./mqttService";
import { credentialsService } from "./credentialsService";

interface PollingTarget {
  slaveId: number;
}

interface PolledClientSnapshot extends PollingTarget {
  relayStates: boolean[];
  inputStates: boolean[];
}

type MqttDoorState = "open" | "closed" | "unknown";

export type CompartmentSnapshotEntry = {
  compartment_number: number;
  door_state: MqttDoorState;
};

/** Canonical JSON key for in-memory snapshot deduplication (sorted by compartment_number). */
export function compartmentSnapshotKey(
  entries: CompartmentSnapshotEntry[],
): string {
  const sorted = [...entries].sort(
    (left, right) => left.compartment_number - right.compartment_number,
  );
  return JSON.stringify(sorted);
}

/** Whether the effective door-state vector differs from the last published snapshot key. */
export function shouldPublishCompartmentSnapshot(
  lastPublishedKey: string | null,
  entries: CompartmentSnapshotEntry[],
): boolean {
  return compartmentSnapshotKey(entries) !== lastPublishedKey;
}

class CoilPollingService {
  private intervalId: NodeJS.Timeout | null = null;
  private pollingInterval: number = 5000; // 5 seconds
  private readonly NUM_CHANNELS = 8; // 8-channel relay board
  private hasWarnedAboutLegacyMultiBoardMapping: boolean = false;
  private isPolling: boolean = false;
  /** Serialized last published snapshot for change detection */
  private lastPublishedSnapshotKey: string | null = null;

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
      `Starting relay/input polling service with interval: ${this.pollingInterval / 1000}s for slave IDs: ${pollingTargets.map((target) => target.slaveId).join(", ")}`,
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
    if (this.isPolling) {
      logger.warn(
        "Skipping poll cycle because the previous cycle is still running",
      );
      return;
    }

    this.isPolling = true;

    try {
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
            target.slaveId,
          );

          const inputStates = await modbusService.readDiscreteInputs(
            0x0000,
            this.NUM_CHANNELS,
            target.slaveId,
          );

          logger.debug(`[slave:${target.slaveId}] Relay states:`, relayStates);
          logger.debug(
            `[slave:${target.slaveId}] Input states (door sensors):`,
            inputStates,
          );

          snapshots.push({
            ...target,
            relayStates,
            inputStates,
          });
        } catch (error) {
          logger.error(
            `[slave:${target.slaveId}] Error polling relay/input status:`,
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
        logger.warn(
          "No reachable Modbus boards responded during polling cycle",
        );
        return;
      }

      await this.maybePublishSnapshot(snapshots);
    } finally {
      this.isPolling = false;
    }
  }

  /**
   * Publish retained compartment_snapshot only when door states change.
   */
  private async maybePublishSnapshot(
    snapshots: PolledClientSnapshot[],
  ): Promise<void> {
    try {
      const credentials = credentialsService.getCredentials();
      if (!credentials || !credentials.username) {
        return;
      }

      const entries = this.buildCompartmentSnapshotEntries(snapshots);
      if (entries.length === 0) {
        return;
      }

      const key = compartmentSnapshotKey(entries);
      if (key === this.lastPublishedSnapshotKey) {
        return;
      }

      const lockerUuid = credentials.username;
      const topic = `locker/${lockerUuid}/state/compartments`;
      const payload = {
        timestamp: new Date().toISOString(),
        compartments: entries,
      };

      await mqttService.publish(topic, payload, { qos: 1, retain: true });
      this.lastPublishedSnapshotKey = key;
    } catch (error) {
      logger.error("Failed to publish compartment snapshot to MQTT:", error);
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

  restart(): void {
    if (!this.intervalId) {
      return;
    }

    this.stop();
    this.start();
  }

  private getPollingTargets(): PollingTarget[] {
    return modbusService.getConfiguredSlaveIds().map((slaveId) => ({
      slaveId,
    }));
  }

  private buildCompartmentSnapshotEntries(
    snapshots: PolledClientSnapshot[],
  ): CompartmentSnapshotEntry[] {
    const configuredCompartments = configLoader.getConfig()?.compartments;

    if (
      configLoader.hasExplicitRuntimeCompartmentsConfig() &&
      configuredCompartments?.length === 0
    ) {
      return [];
    }

    if (!configuredCompartments || configuredCompartments.length === 0) {
      if (snapshots.length > 1 && !this.hasWarnedAboutLegacyMultiBoardMapping) {
        logger.warn(
          "Polling multiple Modbus boards without compartment mapping. Falling back to derived compartment IDs by client order.",
        );
        this.hasWarnedAboutLegacyMultiBoardMapping = true;
      }

      return snapshots
        .flatMap((snapshot) =>
          snapshot.relayStates.map((_, address) =>
            this.entryFromSensor(
              snapshot.slaveId === 1
                ? address + 1
                : (snapshot.slaveId - 1) * this.NUM_CHANNELS + address + 1,
              snapshot.inputStates[address],
            ),
          ),
        )
        .sort(
          (left, right) => left.compartment_number - right.compartment_number,
        );
    }

    const entries: CompartmentSnapshotEntry[] = [];

    for (const compartment of configuredCompartments) {
      const snapshot = snapshots.find((s) => s.slaveId === compartment.slaveId);
      if (!snapshot) {
        entries.push({
          compartment_number: compartment.compartment_number,
          door_state: "unknown",
        });
        continue;
      }

      if (
        compartment.address < 0 ||
        compartment.address >= this.NUM_CHANNELS
      ) {
        logger.warn(
          `Skipping compartment ${compartment.compartment_number}: address ${compartment.address} is outside the supported polling range`,
        );
        entries.push({
          compartment_number: compartment.compartment_number,
          door_state: "unknown",
        });
        continue;
      }

      entries.push(
        this.entryFromSensor(
          compartment.compartment_number,
          snapshot.inputStates[compartment.address],
        ),
      );
    }

    return entries.sort(
      (left, right) => left.compartment_number - right.compartment_number,
    );
  }

  private entryFromSensor(
    compartmentNumber: number,
    inputState: boolean | undefined,
  ): CompartmentSnapshotEntry {
    if (typeof inputState !== "boolean") {
      return {
        compartment_number: compartmentNumber,
        door_state: "unknown",
      };
    }

    return {
      compartment_number: compartmentNumber,
      door_state: inputState ? "open" : "closed",
    };
  }
}

export const coilPollingService = new CoilPollingService();
