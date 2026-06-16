import type { CompartmentTarget, DoorState } from '../domain/compartment';
import type { ConfigRepositoryPort } from '../ports/config.port';
import type { LockerBusPort } from '../ports/locker-bus.port';
import type { OutboundMqttPort } from '../ports/mqtt.port';

export interface CompartmentSnapshotEntry {
  compartment_number: number;
  door_state: DoorState;
}

export class PollCompartmentStateUseCase {
  private polling = false;

  constructor(
    private readonly bus: LockerBusPort,
    private readonly config: ConfigRepositoryPort,
    private readonly outbound: OutboundMqttPort,
    private readonly snapshotTopic: string,
  ) {}

  async pollAndPublish(force = false): Promise<void> {
    if (this.polling && !force) {
      return;
    }

    this.polling = true;
    try {
      const entries = await this.collectSnapshots();
      await this.outbound.publishJson(
        this.snapshotTopic,
        { compartments: entries },
        { qos: 1, retain: true },
      );
    } finally {
      this.polling = false;
    }
  }

  private async collectSnapshots(): Promise<CompartmentSnapshotEntry[]> {
    const compartments = this.config.load().compartments ?? [];
    const entries: CompartmentSnapshotEntry[] = [];

    if (compartments.length === 0) {
      return entries;
    }

    for (const compartment of compartments) {
      const target: CompartmentTarget = {
        compartmentNumber: compartment.compartment_number,
        relayAddress: compartment.address,
        slaveId: compartment.slaveId,
      };

      try {
        const doorState = await this.bus.readDoorSensor(target);
        entries.push({
          compartment_number: compartment.compartment_number,
          door_state: doorState,
        });
      } catch {
        entries.push({
          compartment_number: compartment.compartment_number,
          door_state: 'unknown',
        });
      }
    }

    return entries;
  }
}

export class HeartbeatUseCase {
  private timer: ReturnType<typeof setInterval> | null = null;
  private readonly startTime = Date.now();

  constructor(
    private readonly outbound: OutboundMqttPort,
    private readonly topic: string,
    private intervalMs: number,
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
    await this.outbound.publishJson(this.topic, { uptime_seconds: uptimeSeconds }, { qos: 1 });
  }
}
