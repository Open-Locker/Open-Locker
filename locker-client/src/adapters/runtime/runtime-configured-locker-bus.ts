import PQueue from 'p-queue';
import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import type { HardwareProfile } from '../../domain/config';
import { LockerError, MqttErrorCode } from '../../domain/errors';
import type { ConfigRepositoryPort } from '../../ports/config.port';
import {
  BusPriority,
  type ConnectionState,
  type LockerBusPort,
  type UnlockFeedback,
} from '../../ports/locker-bus.port';

export type LockerBusFactory = (profile: HardwareProfile) => LockerBusPort;

export class RuntimeConfiguredLockerBus implements LockerBusPort {
  private readonly queue = new PQueue({ concurrency: 1 });
  private active: LockerBusPort | null = null;
  private activeProfileKey: string | null = null;
  private shouldBeConnected = false;

  constructor(
    private readonly config: ConfigRepositoryPort,
    private readonly factory: LockerBusFactory,
  ) {}

  connect(): Promise<void> {
    return this.enqueue(async () => {
      this.shouldBeConnected = true;
      await this.reconcile();
    }, BusPriority.MAINTENANCE);
  }

  disconnect(): Promise<void> {
    return this.enqueue(async () => {
      this.shouldBeConnected = false;
      await this.active?.disconnect();
      this.active = null;
      this.activeProfileKey = null;
    }, BusPriority.COMMAND + 1);
  }

  getConnectionState(): ConnectionState {
    return this.active?.getConnectionState() ?? 'disconnected';
  }

  ensureConnected(): Promise<boolean> {
    return this.enqueue(async () => {
      if (!this.active) {
        await this.reconcile();
      }
      return (await this.active?.ensureConnected()) ?? false;
    }, BusPriority.MAINTENANCE);
  }

  reloadRuntimeConfig(): Promise<void> {
    return this.enqueue(() => this.reconcile(true), BusPriority.COMMAND + 1);
  }

  flashRelay(target: CompartmentTarget, durationMs: number): Promise<UnlockFeedback> {
    return this.enqueue(
      () => this.requireActive().flashRelay(target, durationMs),
      BusPriority.COMMAND,
    );
  }

  readRelayState(target: CompartmentTarget): Promise<boolean> {
    return this.enqueue(() => this.requireActive().readRelayState(target), BusPriority.POLL);
  }

  readDoorSensors(slaveId: number, startAddress: number, length: number): Promise<DoorState[]> {
    return this.enqueue(
      () => this.requireActive().readDoorSensors(slaveId, startAddress, length),
      BusPriority.SNAPSHOT,
    );
  }

  initializeBoard(slaveId: number): Promise<void> {
    return this.enqueue(
      () => this.requireActive().initializeBoard(slaveId),
      BusPriority.MAINTENANCE,
    );
  }

  getConfiguredSlaveIds(): number[] {
    return this.config.getConfiguredSlaveIds();
  }

  private async reconcile(forceReload = false): Promise<void> {
    const profile = this.config.load().hardwareProfile;
    const nextKey = profile ? JSON.stringify(profile) : null;
    if (nextKey === this.activeProfileKey && this.active) {
      if (this.shouldBeConnected && this.active.getConnectionState() !== 'connected') {
        await this.active.connect();
        await this.initializeConfiguredBoards(this.active);
        return;
      }
      if (forceReload) {
        await this.active.reloadRuntimeConfig();
        await this.initializeConfiguredBoards(this.active);
      }
      return;
    }

    await this.active?.disconnect();
    this.active = null;
    this.activeProfileKey = null;
    if (!profile) {
      return;
    }

    const next = this.factory(profile);
    this.active = next;
    this.activeProfileKey = nextKey;
    if (this.shouldBeConnected || forceReload) {
      await next.connect();
      await this.initializeConfiguredBoards(next);
    }
  }

  private async initializeConfiguredBoards(bus: LockerBusPort): Promise<void> {
    const slaveIds = this.config.getConfiguredSlaveIds();
    let successCount = 0;
    for (const slaveId of slaveIds) {
      try {
        await bus.initializeBoard(slaveId);
        successCount++;
      } catch {
        // Continue so one absent board does not prevent other boards from being initialized.
      }
    }
    if (slaveIds.length > 0 && successCount === 0) {
      throw new LockerError(
        MqttErrorCode.HARDWARE_ERROR,
        'Runtime hardware initialization failed for every configured board',
      );
    }
  }

  private requireActive(): LockerBusPort {
    if (!this.active) {
      throw new LockerError(
        MqttErrorCode.RUNTIME_CONFIG_NOT_APPLIED,
        'Hardware adapter is not available until apply_config has been applied',
      );
    }
    return this.active;
  }

  private enqueue<T>(operation: () => Promise<T>, priority: number): Promise<T> {
    return this.queue.add(operation, { priority }) as Promise<T>;
  }
}
