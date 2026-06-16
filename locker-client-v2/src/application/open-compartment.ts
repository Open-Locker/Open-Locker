import type { CompartmentTarget } from "../domain/compartment";
import { LockerError, MqttErrorCode } from "../domain/errors";
import type { ConfigRepositoryPort } from "../ports/config.port";
import type { LockerBusPort } from "../ports/locker-bus.port";
import type { SchedulerPort } from "../ports/config.port";

export class OpenCompartmentUseCase {
  private readonly monitoringKeys = new Set<number>();

  constructor(
    private readonly bus: LockerBusPort,
    private readonly config: ConfigRepositoryPort,
    private readonly scheduler: SchedulerPort,
    private readonly monitoringIntervalMs = 500,
  ) {}

  async execute(compartmentNumber: number): Promise<void> {
    const connected = await (this.bus as { ensureConnected?: () => Promise<boolean> }).ensureConnected?.();
    if (connected === false) {
      throw new LockerError(
        MqttErrorCode.MODBUS_ERROR,
        "Cannot open compartment: Modbus connection unavailable",
      );
    }

    const target = this.resolveTarget(compartmentNumber);
    const durationMs = this.config.getFlashDurationMs();

    await this.bus.flashRelay(target, durationMs);
    this.startRelayMonitoring(target);
  }

  stopAllMonitoring(): void {
    this.scheduler.cancelAll();
    this.monitoringKeys.clear();
  }

  private resolveTarget(compartmentNumber: number): CompartmentTarget {
    const compartment = this.config.getCompartmentConfig(compartmentNumber);

    if (!compartment) {
      if (this.config.hasExplicitRuntimeCompartments()) {
        throw new LockerError(
          MqttErrorCode.COMPARTMENT_NOT_FOUND,
          `Compartment ${compartmentNumber} is not configured on this client`,
        );
      }

      const relayAddress = compartmentNumber - 1;
      if (relayAddress < 0 || relayAddress > 7) {
        throw new LockerError(
          MqttErrorCode.COMPARTMENT_NOT_FOUND,
          `Invalid compartment number: ${compartmentNumber}`,
        );
      }

      const slaveIds = this.bus.getConfiguredSlaveIds();
      return {
        compartmentNumber,
        relayAddress,
        slaveId: slaveIds[0] ?? 1,
      };
    }

    return {
      compartmentNumber,
      relayAddress: compartment.address,
      slaveId: compartment.slaveId,
    };
  }

  private startRelayMonitoring(target: CompartmentTarget): void {
    if (this.monitoringKeys.has(target.compartmentNumber)) {
      return;
    }

    this.monitoringKeys.add(target.compartmentNumber);

    const tick = async (): Promise<void> => {
      try {
        const relayOn = await this.bus.readRelayState(target);
        if (!relayOn) {
          this.monitoringKeys.delete(target.compartmentNumber);
          return;
        }
      } catch {
        this.monitoringKeys.delete(target.compartmentNumber);
        return;
      }

      this.scheduler.scheduleAfter(this.monitoringIntervalMs, tick);
    };

    void tick();
  }
}

export async function runStartupFailsafe(
  bus: LockerBusPort,
): Promise<void> {
  const slaveIds = bus.getConfiguredSlaveIds();
  let successCount = 0;

  for (const slaveId of slaveIds) {
    try {
      await bus.turnAllRelaysOff(slaveId);
      successCount++;
    } catch {
      // continue per ADR-0006
    }
  }

  if (successCount === 0 && slaveIds.length > 0) {
    throw new Error("Startup failsafe: all Modbus boards unreachable");
  }
}
