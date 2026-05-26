import { configLoader } from "../config/configLoader";
import type { CompartmentConfig } from "../config/configLoader";
import { logger } from "../helper/logger";
import { modbusService } from "./modbusService";
import { mqttService } from "./mqttService";
import { credentialsService } from "./credentialsService";

interface PollingTarget {
  slaveId: number;
}

interface PolledClientSnapshot extends PollingTarget {
  relayStates: Array<boolean | undefined>;
  inputStates: Array<boolean | undefined>;
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

export function getUniqueConfiguredAddressesForSlave(
  compartments: CompartmentConfig[] | undefined,
  slaveId: number,
  numChannels: number,
): number[] {
  if (!compartments || compartments.length === 0) {
    return [];
  }

  return [
    ...new Set(
      compartments
        .filter((compartment) => compartment.slaveId === slaveId)
        .map((compartment) => compartment.address)
        .filter(
          (address) =>
            Number.isInteger(address) && address >= 0 && address < numChannels,
        ),
    ),
  ].sort((left, right) => left - right);
}

export function isReconnectableModbusError(error: unknown): boolean {
  return (
    error instanceof Error &&
    (error.message.includes("Port Not Open") ||
      error.message.includes("ECONNREFUSED"))
  );
}

export function getModbusPollingErrorDetails(error: unknown): {
  errorName?: string;
  errorMessage: string;
  errno?: unknown;
} {
  if (error instanceof Error) {
    return {
      errorName: error.name,
      errorMessage: error.message,
      errno:
        "errno" in error ? (error as Error & { errno?: unknown }).errno : undefined,
    };
  }

  if (error && typeof error === "object") {
    const record = error as Record<string, unknown>;
    return {
      errorName: typeof record.name === "string" ? record.name : undefined,
      errorMessage:
        typeof record.message === "string" ? record.message : JSON.stringify(record),
      errno: record.errno,
    };
  }

  return {
    errorMessage: String(error),
  };
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
      await this.runPollCycle(false);
    } finally {
      this.isPolling = false;
    }
  }

  /**
   * Poll Modbus once and publish locker/.../state/compartments.
   * Used after a successful open so the backend gets a fresh snapshot without
   * waiting for the next interval. With `force: true`, republishes even when the
   * door-state vector matches the last publish (new timestamp on retained message).
   */
  async publishSnapshotNow(options: { force?: boolean } = {}): Promise<void> {
    if (this.isPolling) {
      logger.debug(
        "Skipping immediate compartment snapshot: poll cycle already in progress",
      );
      return;
    }

    this.isPolling = true;
    try {
      await this.runPollCycle(options.force === true);
    } catch (error) {
      logger.error("Immediate compartment snapshot poll failed:", error);
    } finally {
      this.isPolling = false;
    }
  }

  private async runPollCycle(forcePublish: boolean): Promise<void> {
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
        const { relayStates, inputStates } =
          await this.pollTargetSnapshot(target);

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
        if (isReconnectableModbusError(error)) {
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

    await this.maybePublishSnapshot(snapshots, forcePublish);
  }

  private async pollTargetSnapshot(
    target: PollingTarget,
  ): Promise<Omit<PolledClientSnapshot, "slaveId">> {
    const configuredAddresses = this.getConfiguredAddressesForSlave(
      target.slaveId,
    );

    if (configuredAddresses.length === 0) {
      return this.pollAllChannels(target.slaveId);
    }

    return this.pollConfiguredChannels(target.slaveId, configuredAddresses);
  }

  private async pollAllChannels(
    slaveId: number,
  ): Promise<Omit<PolledClientSnapshot, "slaveId">> {
    const relayStates = await modbusService.readCoils(
      0x0000,
      this.NUM_CHANNELS,
      slaveId,
    );

    const inputStates = await modbusService.readDiscreteInputs(
      0x0000,
      this.NUM_CHANNELS,
      slaveId,
    );

    return { relayStates, inputStates };
  }

  private async pollConfiguredChannels(
    slaveId: number,
    addresses: number[],
  ): Promise<Omit<PolledClientSnapshot, "slaveId">> {
    const relayStates = this.createEmptyChannelStates();
    const inputStates = this.createEmptyChannelStates();

    for (const address of addresses) {
      inputStates[address] = await this.readSingleDiscreteInput(slaveId, address);
    }

    return { relayStates, inputStates };
  }

  private createEmptyChannelStates(): Array<boolean | undefined> {
    return Array.from({ length: this.NUM_CHANNELS });
  }

  private async readSingleDiscreteInput(
    slaveId: number,
    address: number,
  ): Promise<boolean | undefined> {
    try {
      const [state] = await modbusService.readDiscreteInputs(
        address,
        1,
        slaveId,
        { logErrors: false },
      );
      return state;
    } catch (error) {
      if (isReconnectableModbusError(error)) {
        throw error;
      }

      this.logPollingReadFailure(slaveId, address, error);
      return undefined;
    }
  }

  private logPollingReadFailure(
    slaveId: number,
    address: number,
    error: unknown,
  ): void {
    const errorDetails = getModbusPollingErrorDetails(error);

    logger.warn("Modbus polling read failed", {
      operation: "read_discrete_input",
      slaveId,
      address,
      ...errorDetails,
    });
  }

  /**
   * Publish retained compartment_snapshot only when door states change.
   */
  private async maybePublishSnapshot(
    snapshots: PolledClientSnapshot[],
    forcePublish = false,
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
      if (!forcePublish && key === this.lastPublishedSnapshotKey) {
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

  private getConfiguredAddressesForSlave(slaveId: number): number[] {
    return getUniqueConfiguredAddressesForSlave(
      configLoader.getConfig()?.compartments,
      slaveId,
      this.NUM_CHANNELS,
    );
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
