import type { CompartmentTarget, DoorState } from '../domain/compartment';
import { DOOR_DETECTION_POLL_INTERVAL_MS, RelayFireLog } from '../domain/door-detection';
import { HardwareTransportError, LockerError, MqttErrorCode } from '../domain/errors';
import type { ConfigRepositoryPort } from '../ports/config.port';
import type { DoorEventPublisherPort } from '../ports/door-events.port';
import type { LockerBusPort } from '../ports/locker-bus.port';
import type { SchedulerPort } from '../ports/config.port';
import { noopLogger, type LoggerPort } from '../ports/logging.port';

export interface OpenCompartmentDeps {
  bus: LockerBusPort;
  config: ConfigRepositoryPort;
  scheduler: SchedulerPort;
  doorEvents: DoorEventPublisherPort;
  relayFireLog: RelayFireLog;
  log?: LoggerPort;
  monitoringIntervalMs?: number;
  now?: () => number;
}

/**
 * Fires the unlock pulse, then watches the door to find out whether it actually
 * opened.
 *
 * `execute()` returns as soon as the pulse is sent so the caller can acknowledge
 * immediately; detection continues in the background and reports its own
 * outcome. The relay pulse and the door opening are separate facts and are
 * reported separately.
 */
export class OpenCompartmentUseCase {
  private readonly monitoringKeys = new Set<number>();

  private readonly bus: LockerBusPort;

  private readonly config: ConfigRepositoryPort;

  private readonly scheduler: SchedulerPort;

  private readonly doorEvents: DoorEventPublisherPort;

  private readonly relayFireLog: RelayFireLog;

  private readonly log: LoggerPort;

  private readonly monitoringIntervalMs: number;

  private readonly now: () => number;

  constructor(deps: OpenCompartmentDeps) {
    this.bus = deps.bus;
    this.config = deps.config;
    this.scheduler = deps.scheduler;
    this.doorEvents = deps.doorEvents;
    this.relayFireLog = deps.relayFireLog;
    this.log = deps.log ?? noopLogger;
    this.monitoringIntervalMs = deps.monitoringIntervalMs ?? 500;
    this.now = deps.now ?? (() => Date.now());
  }

  async execute(compartmentNumber: number, transactionId: string): Promise<void> {
    const { target, targetConfigKey, doorStateBefore, unlockFeedback } =
      await this.bus.runExclusive(async (exclusiveBus) => {
        const target = this.resolveTarget(compartmentNumber);
        const connected = await exclusiveBus.ensureConnected();
        if (!connected) {
          throw new LockerError(
            MqttErrorCode.HARDWARE_ERROR,
            'Cannot open compartment: hardware bus unavailable',
          );
        }

        const durationMs = this.config.getFlashDurationMs();

        // Read before firing: a door that is already open would otherwise be
        // indistinguishable from one the pulse opened.
        const doorStateBefore = await this.readDoorState(target, exclusiveBus);
        const unlockFeedback = await exclusiveBus.flashRelay(target, durationMs);
        return {
          target,
          targetConfigKey: this.targetConfigKey(target),
          doorStateBefore,
          unlockFeedback,
        };
      });
    this.relayFireLog.recordFire(compartmentNumber, this.now());
    this.startRelayMonitoring(target, targetConfigKey);

    if (doorStateBefore === 'open') {
      await this.reportOutcome({
        compartmentNumber,
        transactionId,
        outcome: 'already_open',
        detectionMs: null,
      });

      return;
    }

    if (unlockFeedback === 'opened') {
      await this.reportOutcome({
        compartmentNumber,
        transactionId,
        outcome: 'opened',
        detectionMs: 0,
      });
      return;
    }

    if (unlockFeedback === 'failed') {
      await this.reportOutcome({
        compartmentNumber,
        transactionId,
        outcome: 'door_jammed',
        detectionMs: null,
      });
      return;
    }

    this.startDoorDetection(target, transactionId, targetConfigKey);
  }

  stopAllMonitoring(): void {
    this.scheduler.cancelAll();
    this.monitoringKeys.clear();
    this.relayFireLog.clear();
  }

  /**
   * Detection window length. Follows the bank's heartbeat interval rather than a
   * dedicated setting; the trade-off is recorded there.
   */
  private detectionTimeoutMs(): number {
    return Math.max(1, this.config.getHeartbeatIntervalSeconds()) * 1000;
  }

  private startDoorDetection(
    target: CompartmentTarget,
    transactionId: string,
    targetConfigKey: string,
  ): void {
    const compartmentNumber = target.compartmentNumber;
    const timeoutMs = this.detectionTimeoutMs();
    const startedAt = this.now();

    this.relayFireLog.beginDetection(compartmentNumber);

    const tick = async (): Promise<void> => {
      if (this.targetConfigKey(target) !== targetConfigKey) {
        this.relayFireLog.endDetection(compartmentNumber);
        this.log.warn('Door detection stopped because the compartment mapping changed', {
          compartmentNumber,
        });
        return;
      }

      const doorState = await this.readDoorState(target);
      const elapsedMs = this.now() - startedAt;

      if (doorState === 'open') {
        this.relayFireLog.endDetection(compartmentNumber);
        await this.reportOutcome({
          compartmentNumber,
          transactionId,
          outcome: 'opened',
          detectionMs: elapsedMs,
        });

        return;
      }

      if (elapsedMs >= timeoutMs) {
        this.relayFireLog.endDetection(compartmentNumber);
        await this.reportOutcome({
          compartmentNumber,
          transactionId,
          outcome: 'door_jammed',
          detectionMs: null,
        });

        return;
      }

      this.scheduler.scheduleAfter(DOOR_DETECTION_POLL_INTERVAL_MS, tick);
    };

    this.scheduler.scheduleAfter(DOOR_DETECTION_POLL_INTERVAL_MS, tick);
  }

  private async reportOutcome(event: {
    compartmentNumber: number;
    transactionId: string;
    outcome: 'opened' | 'already_open' | 'door_jammed';
    detectionMs: number | null;
  }): Promise<void> {
    try {
      await this.doorEvents.publishOpenDetection(event);
    } catch (error) {
      this.log.warn('Door detection event publish failed', {
        compartmentNumber: event.compartmentNumber,
        outcome: event.outcome,
        error: error instanceof Error ? error.message : String(error),
      });
    }
  }

  /** Single-compartment door read; `unknown` on any bus failure. */
  private async readDoorState(
    target: CompartmentTarget,
    bus: LockerBusPort = this.bus,
  ): Promise<DoorState> {
    try {
      const states = await bus.readDoorSensors(target.slaveId, target.relayAddress, 1);

      return states[0] ?? 'unknown';
    } catch {
      return 'unknown';
    }
  }

  private resolveTarget(compartmentNumber: number): CompartmentTarget {
    const effective = this.config.load();

    if (effective.compartments === undefined) {
      throw new LockerError(
        MqttErrorCode.RUNTIME_CONFIG_NOT_APPLIED,
        'Compartment mapping is not available until apply_config has been applied',
      );
    }

    const compartment = effective.compartments.find(
      (entry) => entry.compartment_number === compartmentNumber,
    );
    if (!compartment) {
      throw new LockerError(
        MqttErrorCode.COMPARTMENT_NOT_FOUND,
        `Compartment ${compartmentNumber} is not configured on this client`,
      );
    }

    return {
      compartmentNumber,
      relayAddress: compartment.address,
      slaveId: compartment.slaveId,
    };
  }

  private startRelayMonitoring(target: CompartmentTarget, targetConfigKey: string): void {
    if (this.monitoringKeys.has(target.compartmentNumber)) {
      return;
    }

    this.monitoringKeys.add(target.compartmentNumber);

    const tick = async (): Promise<void> => {
      if (this.targetConfigKey(target) !== targetConfigKey) {
        this.monitoringKeys.delete(target.compartmentNumber);
        return;
      }

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

  private targetConfigKey(target: CompartmentTarget): string {
    const effective = this.config.load();
    const mapping =
      effective.compartments?.find(
        (entry) => entry.compartment_number === target.compartmentNumber,
      ) ?? null;
    return JSON.stringify({
      hardwareProfile: effective.hardwareProfile ?? null,
      mapping,
    });
  }
}

export async function runStartupInitialization(bus: LockerBusPort): Promise<void> {
  const slaveIds = bus.getConfiguredSlaveIds();
  let successCount = 0;

  for (const slaveId of slaveIds) {
    try {
      await bus.initializeBoard(slaveId);
      successCount++;
    } catch {
      // One unreachable board must not stop the others from being cleared.
    }
  }

  if (successCount === 0 && slaveIds.length > 0) {
    throw new HardwareTransportError('Startup hardware initialization: all boards unreachable');
  }
}
