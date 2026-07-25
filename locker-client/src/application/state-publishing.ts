import type { CompartmentConfig, DoorState } from '../domain/compartment';
import type { ConfigRepositoryPort } from '../ports/config.port';
import type { LockerBusPort } from '../ports/locker-bus.port';
import type { OutboundMqttPort } from '../ports/mqtt.port';
import { noopLogger, type LoggerPort } from '../ports/logging.port';

export interface CompartmentSnapshotEntry {
  compartment_number: number;
  door_state: DoorState;
}

interface CollectedSnapshot {
  entries: CompartmentSnapshotEntry[];
  configKey: string;
}

export const COMPARTMENT_POLL_INTERVAL_MS = 500;
export const UNKNOWN_PUBLISH_THRESHOLD = 3;

export class PollCompartmentStateUseCase {
  private activePoll: Promise<void> | null = null;
  private forcePending = false;
  private lastPublishedSnapshotKey: string | null = null;
  private readonly lastKnownDoorStates = new Map<string, Exclude<DoorState, 'unknown'>>();
  private readonly consecutiveUnknownReads = new Map<string, number>();

  constructor(
    private readonly bus: LockerBusPort,
    private readonly config: ConfigRepositoryPort,
    private readonly outbound: OutboundMqttPort,
    private readonly snapshotTopic: string,
    private readonly log: LoggerPort = noopLogger,
  ) {}

  pollAndPublish(force = false): Promise<void> {
    if (this.activePoll) {
      if (force) {
        this.forcePending = true;
      }
      return this.activePoll;
    }

    this.activePoll = this.runPollLoop(force);
    return this.activePoll;
  }

  private async runPollLoop(force: boolean): Promise<void> {
    let forceCurrentPoll = force;

    try {
      do {
        this.forcePending = false;
        await this.pollOnce(forceCurrentPoll);
        forceCurrentPoll = this.forcePending;
      } while (forceCurrentPoll);
    } finally {
      this.activePoll = null;
    }
  }

  private async pollOnce(force: boolean): Promise<void> {
    try {
      const snapshot = await this.collectSnapshots();
      if (snapshot === null) {
        return;
      }

      if (this.currentConfigKey() !== snapshot.configKey) {
        this.forcePending = true;
        return;
      }

      const snapshotKey = JSON.stringify(snapshot.entries);
      if (!force && snapshotKey === this.lastPublishedSnapshotKey) {
        return;
      }

      await this.outbound.publishJson(
        this.snapshotTopic,
        { compartments: snapshot.entries },
        { qos: 1, retain: true },
      );
      this.lastPublishedSnapshotKey = snapshotKey;
      if (this.currentConfigKey() !== snapshot.configKey) {
        this.forcePending = true;
      }
    } catch (error) {
      this.log.warn('Compartment snapshot publish failed', {
        error: error instanceof Error ? error.message : String(error),
      });
    }
  }

  private async collectSnapshots(): Promise<CollectedSnapshot | null> {
    const compartments = this.config.load().compartments;
    if (compartments === undefined) {
      return null;
    }

    const configKey = this.configKey(compartments);
    const compartmentsBySlave = this.groupBySlaveId(compartments);
    const entries: CompartmentSnapshotEntry[] = [];
    const activeTargetKeys = new Set<string>();

    for (const [slaveId, boardCompartments] of [...compartmentsBySlave.entries()].toSorted(
      ([left], [right]) => left - right,
    )) {
      const addresses = boardCompartments.map((compartment) => compartment.address);
      const startAddress = Math.min(...addresses);
      const length = Math.max(...addresses) - startAddress + 1;
      let observedStates: DoorState[];
      try {
        observedStates = await this.bus.readDoorSensors(slaveId, startAddress, length);
      } catch {
        observedStates = [];
      }

      for (const compartment of boardCompartments) {
        const targetKey = this.targetKey(compartment);
        activeTargetKeys.add(targetKey);
        entries.push({
          compartment_number: compartment.compartment_number,
          door_state: this.resolveEffectiveDoorState(
            targetKey,
            observedStates[compartment.address - startAddress] ?? 'unknown',
          ),
        });
      }
    }

    this.removeStaleTargets(activeTargetKeys);

    return {
      entries: entries.toSorted(
        (left, right) => left.compartment_number - right.compartment_number,
      ),
      configKey,
    };
  }

  private groupBySlaveId(compartments: CompartmentConfig[]): Map<number, CompartmentConfig[]> {
    const grouped = new Map<number, CompartmentConfig[]>();
    for (const compartment of compartments) {
      const boardCompartments = grouped.get(compartment.slaveId) ?? [];
      boardCompartments.push(compartment);
      grouped.set(compartment.slaveId, boardCompartments);
    }
    return grouped;
  }

  private resolveEffectiveDoorState(targetKey: string, observedState: DoorState): DoorState {
    if (observedState !== 'unknown') {
      this.lastKnownDoorStates.set(targetKey, observedState);
      this.consecutiveUnknownReads.delete(targetKey);
      return observedState;
    }

    const failures = (this.consecutiveUnknownReads.get(targetKey) ?? 0) + 1;
    this.consecutiveUnknownReads.set(targetKey, failures);
    const lastKnownState = this.lastKnownDoorStates.get(targetKey);

    if (lastKnownState && failures < UNKNOWN_PUBLISH_THRESHOLD) {
      return lastKnownState;
    }

    return 'unknown';
  }

  private targetKey(compartment: CompartmentConfig): string {
    return `${compartment.compartment_number}:${compartment.slaveId}:${compartment.address}`;
  }

  private currentConfigKey(): string {
    const compartments = this.config.load().compartments;
    return compartments === undefined ? 'unconfigured' : this.configKey(compartments);
  }

  private configKey(compartments: CompartmentConfig[]): string {
    return JSON.stringify(
      [...compartments].toSorted(
        (left, right) =>
          left.compartment_number - right.compartment_number ||
          left.slaveId - right.slaveId ||
          left.address - right.address,
      ),
    );
  }

  private removeStaleTargets(activeTargetKeys: Set<string>): void {
    for (const targetKey of this.lastKnownDoorStates.keys()) {
      if (!activeTargetKeys.has(targetKey)) {
        this.lastKnownDoorStates.delete(targetKey);
      }
    }
    for (const targetKey of this.consecutiveUnknownReads.keys()) {
      if (!activeTargetKeys.has(targetKey)) {
        this.consecutiveUnknownReads.delete(targetKey);
      }
    }
  }
}

export class HeartbeatUseCase {
  private timer: ReturnType<typeof setInterval> | null = null;
  private readonly startTime = Date.now();

  constructor(
    private readonly outbound: OutboundMqttPort,
    private readonly topic: string,
    private intervalMs: number,
    private readonly log: LoggerPort = noopLogger,
  ) {}

  start(): void {
    this.stop();
    void this.publish();
    this.timer = setInterval(() => {
      void this.publish();
    }, this.intervalMs);
  }

  stop(): void {
    if (this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }
  }

  restart(intervalMs?: number): void {
    if (intervalMs !== undefined) {
      this.intervalMs = intervalMs;
    }
    this.start();
  }

  private async publish(): Promise<void> {
    const uptimeSeconds = Math.floor((Date.now() - this.startTime) / 1000);
    try {
      await this.outbound.publishJson(this.topic, { uptime_seconds: uptimeSeconds }, { qos: 1 });
    } catch (error) {
      this.log.warn('Heartbeat publish failed', {
        error: error instanceof Error ? error.message : String(error),
      });
    }
  }
}
