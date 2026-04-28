import { configLoader } from "../config/configLoader";
import {
  clearRuntimeConfigOverlay,
  computeAppliedConfigHash,
  loadRuntimeConfigOverlay,
  normalizeCompartments,
  RuntimeConfigOverlay,
  saveRuntimeConfigOverlay,
} from "../config/runtimeConfig";
import { logger } from "../helper/logger";
import { ApplyConfigCommand, MQTTErrorCode } from "../types/mqtt";
import { coilPollingService } from "./coilPollingService";
import { heartbeatService } from "./heartbeatService";
import { modbusService } from "./modbusService";

const MAX_RELAY_ADDRESS = 7;

export interface ApplyConfigResult {
  appliedConfigHash: string;
  /** Optional human-readable confirmation (AsyncAPI success responses may omit `message`). */
  message?: string;
}

export interface RuntimeConfigApplier {
  applyConfig(command: ApplyConfigCommand): Promise<ApplyConfigResult>;
}

interface RuntimeConfigOverlayStore {
  load(): RuntimeConfigOverlay | null;
  save(overlay: RuntimeConfigOverlay): RuntimeConfigOverlay;
  clear(): void;
}

interface ReloadableConfigLoader {
  loadConfig(): ReturnType<typeof configLoader.loadConfig>;
  reloadConfig(): ReturnType<typeof configLoader.reloadConfig>;
}

interface RestartableService {
  restart(): void;
}

interface ReloadableModbusService {
  reloadRuntimeConfig(): Promise<void>;
}

export class ApplyConfigExecutionError extends Error {
  constructor(
    public readonly errorCode: MQTTErrorCode,
    message: string,
  ) {
    super(message);
    this.name = "ApplyConfigExecutionError";
  }
}

export class ApplyConfigService implements RuntimeConfigApplier {
  constructor(
    private readonly runtimeConfigOverlayStore: RuntimeConfigOverlayStore = {
      load: loadRuntimeConfigOverlay,
      save: saveRuntimeConfigOverlay,
      clear: clearRuntimeConfigOverlay,
    },
    private readonly reloadableConfigLoader: ReloadableConfigLoader = configLoader,
    private readonly restartableHeartbeatService: RestartableService = heartbeatService,
    private readonly reloadableModbusService: ReloadableModbusService = modbusService,
    private readonly restartableCoilPollingService: RestartableService = coilPollingService,
  ) {}

  async applyConfig(command: ApplyConfigCommand): Promise<ApplyConfigResult> {
    const previousOverlay = this.runtimeConfigOverlayStore.load();
    const nextOverlay = this.buildRuntimeOverlay(command);

    try {
      this.runtimeConfigOverlayStore.save(nextOverlay);
      this.reloadableConfigLoader.reloadConfig();
      this.restartableHeartbeatService.restart();
      await this.reloadableModbusService.reloadRuntimeConfig();
      this.restartableCoilPollingService.restart();

      logger.info("apply_config successfully applied", {
        transaction_id: command.transaction_id,
        applied_config_hash: nextOverlay.appliedConfigHash,
        heartbeat_interval_seconds: nextOverlay.mqtt?.heartbeatInterval,
        compartment_count: nextOverlay.compartments?.length ?? 0,
      });

      return {
        appliedConfigHash: nextOverlay.appliedConfigHash!,
        message: "Config applied.",
      };
    } catch (error) {
      await this.rollback(previousOverlay, error);
      throw error;
    }
  }

  private buildRuntimeOverlay(command: ApplyConfigCommand): RuntimeConfigOverlay {
    const heartbeatInterval = command.data.heartbeat_interval_seconds;
    const normalizedCompartments = normalizeCompartments(command.data.compartments);

    if (!Number.isInteger(heartbeatInterval) || heartbeatInterval <= 0) {
      throw new ApplyConfigExecutionError(
        MQTTErrorCode.INVALID_CONFIG,
        "heartbeat_interval_seconds must be a positive integer",
      );
    }

    this.validateCompartments(normalizedCompartments);

    const computedHash = computeAppliedConfigHash(normalizedCompartments);
    if (!hashEquals(command.data.config_hash, computedHash)) {
      throw new ApplyConfigExecutionError(
        MQTTErrorCode.INVALID_CONFIG,
        "config_hash does not match the provided compartments mapping",
      );
    }

    return {
      mqtt: {
        heartbeatInterval,
      },
      compartments: normalizedCompartments,
      appliedConfigHash: computedHash,
      updatedAt: new Date().toISOString(),
    };
  }

  private validateCompartments(
    compartments: {
      compartment_number: number;
      slaveId: number;
      address: number;
    }[],
  ): void {
    const seenCompartmentNumbers = new Set<number>();
    const seenTargets = new Set<string>();

    for (const compartment of compartments) {
      if (
        !Number.isInteger(compartment.compartment_number) ||
        compartment.compartment_number <= 0
      ) {
        throw new ApplyConfigExecutionError(
          MQTTErrorCode.INVALID_CONFIG,
          "compartment_number values must be positive integers",
        );
      }

      if (!Number.isInteger(compartment.slaveId) || compartment.slaveId <= 0) {
        throw new ApplyConfigExecutionError(
          MQTTErrorCode.INVALID_CONFIG,
          "compartment slaveIds must be positive integers",
        );
      }

      if (
        !Number.isInteger(compartment.address) ||
        compartment.address < 0 ||
        compartment.address > MAX_RELAY_ADDRESS
      ) {
        throw new ApplyConfigExecutionError(
          MQTTErrorCode.INVALID_CONFIG,
          `compartment addresses must be integers between 0 and ${MAX_RELAY_ADDRESS}`,
        );
      }

      if (seenCompartmentNumbers.has(compartment.compartment_number)) {
        throw new ApplyConfigExecutionError(
          MQTTErrorCode.INVALID_CONFIG,
          `duplicate compartment_number ${compartment.compartment_number} is not allowed`,
        );
      }

      const targetKey = `${compartment.slaveId}:${compartment.address}`;
      if (seenTargets.has(targetKey)) {
        throw new ApplyConfigExecutionError(
          MQTTErrorCode.INVALID_CONFIG,
          `duplicate relay target ${targetKey} is not allowed`,
        );
      }

      seenCompartmentNumbers.add(compartment.compartment_number);
      seenTargets.add(targetKey);
    }
  }

  private async rollback(
    previousOverlay: RuntimeConfigOverlay | null,
    error: unknown,
  ): Promise<void> {
    try {
      if (previousOverlay) {
        this.runtimeConfigOverlayStore.save(previousOverlay);
      } else {
        this.runtimeConfigOverlayStore.clear();
      }

      this.reloadableConfigLoader.reloadConfig();
      this.restartableHeartbeatService.restart();
      await this.reloadableModbusService.reloadRuntimeConfig();
      this.restartableCoilPollingService.restart();
      logger.warn("Rolled back runtime config overlay after apply_config failure");
    } catch (rollbackError) {
      logger.error("Failed to roll back runtime config overlay:", rollbackError);
    }

    if (error instanceof ApplyConfigExecutionError) {
      throw error;
    }

    throw new ApplyConfigExecutionError(
      MQTTErrorCode.HARDWARE_ERROR,
      error instanceof Error ? error.message : "Failed to apply config",
    );
  }
}

function hashEquals(left: string, right: string): boolean {
  return left.length === right.length && left.toLowerCase() === right.toLowerCase();
}

export const applyConfigService = new ApplyConfigService();
