import type { CompartmentTarget, DoorState } from '../domain/compartment';
import { ActuationLog, DOOR_DETECTION_POLL_INTERVAL_MS } from '../domain/door-detection';
import { LockerError, MqttErrorCode, UnlockOutcomeUnknownError } from '../domain/errors';
import type { ConfigRepositoryPort } from '../ports/config.port';
import type { DoorEventPublisherPort } from '../ports/door-events.port';
import type { LockerBusPort, UnlockObservation } from '../ports/locker-bus.port';
import type { SchedulerPort } from '../ports/config.port';
import { noopLogger, type LoggerPort } from '../ports/logging.port';

export interface OpenCompartmentDeps {
  bus: LockerBusPort;
  config: ConfigRepositoryPort;
  scheduler: SchedulerPort;
  doorEvents: DoorEventPublisherPort;
  actuationLog: ActuationLog;
  log?: LoggerPort;
  now?: () => number;
}

/**
 * Unlocks the compartment, then watches the door to find out whether it actually
 * opened.
 *
 * `execute()` returns as soon as the controller accepts the unlock so the caller
 * can acknowledge immediately; detection continues in the background and
 * reports its own outcome. The unlock and the door opening are separate facts
 * and are reported separately.
 */
export class OpenCompartmentUseCase {
  private readonly bus: LockerBusPort;

  private readonly config: ConfigRepositoryPort;

  private readonly scheduler: SchedulerPort;

  private readonly doorEvents: DoorEventPublisherPort;

  private readonly actuationLog: ActuationLog;

  private readonly log: LoggerPort;

  private readonly now: () => number;

  constructor(deps: OpenCompartmentDeps) {
    this.bus = deps.bus;
    this.config = deps.config;
    this.scheduler = deps.scheduler;
    this.doorEvents = deps.doorEvents;
    this.actuationLog = deps.actuationLog;
    this.log = deps.log ?? noopLogger;
    this.now = deps.now ?? (() => Date.now());
  }

  async execute(compartmentNumber: number, transactionId: string): Promise<void> {
    const startedAt = this.now();
    const { target, targetConfigKey, observation } = await this.bus.runExclusive(
      async (exclusiveBus) => {
        const resolvedTarget = this.resolveTarget(compartmentNumber);
        try {
          return {
            target: resolvedTarget,
            targetConfigKey: this.targetConfigKey(resolvedTarget),
            observation: await exclusiveBus.unlockCompartment(resolvedTarget),
          };
        } catch (error) {
          if (error instanceof UnlockOutcomeUnknownError) {
            // The lock may have released: a door opening now is not uncommanded.
            this.actuationLog.recordActuation(compartmentNumber, this.now());
          }
          throw error;
        }
      },
    );
    this.actuationLog.recordActuation(compartmentNumber, this.now());
    this.startDoorDetection(target, transactionId, targetConfigKey, startedAt, observation);
  }

  stopAllMonitoring(): void {
    this.scheduler.cancelAll();
    this.actuationLog.clear();
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
    startedAt: number,
    observation: UnlockObservation,
  ): void {
    const compartmentNumber = target.compartmentNumber;
    const timeoutMs = this.detectionTimeoutMs();
    // The window starts with detection, not with the command: a slow unlock
    // (queue wait, reconnect) must not use up the door's time to open.
    // `detectionMs` still counts from the command.
    const detectionStartedAt = this.now();

    this.actuationLog.beginDetection(compartmentNumber);

    if (observation.doorState === 'open') {
      const detectionMs = this.now() - startedAt;
      // Scheduled rather than published inline, so the outcome follows the
      // command response and stopAllMonitoring() can still cancel it.
      this.scheduler.scheduleAfter(0, async () => {
        this.actuationLog.endDetection(compartmentNumber);
        await this.reportOutcome({
          compartmentNumber,
          transactionId,
          outcome: 'opened',
          detectionMs,
        });
      });

      return;
    }

    const tick = async (): Promise<void> => {
      if (this.targetConfigKey(target) !== targetConfigKey) {
        this.actuationLog.endDetection(compartmentNumber);
        this.log.warn('Door detection stopped because the compartment mapping changed', {
          compartmentNumber,
        });
        return;
      }

      const doorState = await this.readDoorState(target);
      const nowMs = this.now();
      const elapsedMs = nowMs - startedAt;

      if (doorState === 'open') {
        this.actuationLog.endDetection(compartmentNumber);
        await this.reportOutcome({
          compartmentNumber,
          transactionId,
          outcome: 'opened',
          detectionMs: elapsedMs,
        });

        return;
      }

      if (nowMs - detectionStartedAt >= timeoutMs) {
        this.actuationLog.endDetection(compartmentNumber);
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
    outcome: 'opened' | 'door_jammed';
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
  private async readDoorState(target: CompartmentTarget): Promise<DoorState> {
    try {
      const states = await this.bus.readCompartmentStates(target.boardAddress, [target.address]);

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
      boardAddress: compartment.slaveId,
      address: compartment.address,
    };
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
