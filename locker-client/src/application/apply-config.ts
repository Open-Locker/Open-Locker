import type { CompartmentConfig } from '../domain/compartment';
import { computeAppliedConfigHash, normalizeCompartments } from '../domain/config-normalization';
import { LockerError, MqttErrorCode } from '../domain/errors';
import type { ApplyConfigCommand } from '../domain/mqtt-schemas';
import type { ConfigRepositoryPort, RuntimeOverlayStorePort } from '../ports/config.port';
import type { LockerBusPort } from '../ports/locker-bus.port';

export interface ApplyConfigResult {
  appliedConfigHash: string;
  message?: string;
}

export interface ApplyConfigDependencies {
  overlayStore: RuntimeOverlayStorePort;
  config: ConfigRepositoryPort;
  bus: LockerBusPort;
  restartHeartbeat: () => void;
  restartPolling: () => void;
}

export class ApplyConfigUseCase {
  constructor(private readonly deps: ApplyConfigDependencies) {}

  async execute(command: ApplyConfigCommand): Promise<ApplyConfigResult> {
    const previous = this.deps.overlayStore.load();

    try {
      const overlay = this.buildOverlay(command);
      this.deps.overlayStore.save(overlay);
      this.deps.config.reload();
      this.deps.restartHeartbeat();
      await this.deps.bus.reloadRuntimeConfig();
      this.deps.restartPolling();

      return {
        appliedConfigHash: overlay.appliedConfigHash!,
        message: 'Config applied.',
      };
    } catch (error) {
      await this.rollback(previous);
      throw error;
    }
  }

  private buildOverlay(command: ApplyConfigCommand) {
    const normalized = normalizeCompartments(command.data.compartments);
    this.validateCompartments(normalized);
    const hash = computeAppliedConfigHash(normalized);

    if (hash.toLowerCase() !== command.data.config_hash.toLowerCase()) {
      throw new LockerError(
        MqttErrorCode.INVALID_CONFIG,
        'config_hash does not match the provided compartments mapping',
      );
    }

    return {
      mqtt: { heartbeatInterval: command.data.heartbeat_interval_seconds },
      compartments: normalized,
      appliedConfigHash: hash,
      updatedAt: new Date().toISOString(),
    };
  }

  private validateCompartments(compartments: CompartmentConfig[]): void {
    const seenNumbers = new Set<number>();
    const seenTargets = new Set<string>();

    for (const c of compartments) {
      if (c.address > 7) {
        throw new LockerError(
          MqttErrorCode.INVALID_CONFIG,
          'compartment addresses must be between 0 and 7',
        );
      }
      if (seenNumbers.has(c.compartment_number)) {
        throw new LockerError(
          MqttErrorCode.INVALID_CONFIG,
          `duplicate compartment_number ${c.compartment_number}`,
        );
      }
      const target = `${c.slaveId}:${c.address}`;
      if (seenTargets.has(target)) {
        throw new LockerError(MqttErrorCode.INVALID_CONFIG, `duplicate relay target ${target}`);
      }
      seenNumbers.add(c.compartment_number);
      seenTargets.add(target);
    }
  }

  private async rollback(previous: ReturnType<RuntimeOverlayStorePort['load']>): Promise<void> {
    if (previous) {
      this.deps.overlayStore.save(previous);
    } else {
      this.deps.overlayStore.clear();
    }
    this.deps.config.reload();
    this.deps.restartHeartbeat();
    await this.deps.bus.reloadRuntimeConfig();
    this.deps.restartPolling();
  }
}
