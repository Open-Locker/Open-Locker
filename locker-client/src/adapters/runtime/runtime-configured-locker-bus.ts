import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import type { HardwareProfile } from '../../domain/config';
import { LockerError, MqttErrorCode } from '../../domain/errors';
import type { ConfigRepositoryPort } from '../../ports/config.port';
import type { ConnectionState, LockerBusPort, UnlockFeedback } from '../../ports/locker-bus.port';

export type LockerBusFactory = (profile: HardwareProfile) => LockerBusPort;

export class RuntimeConfiguredLockerBus implements LockerBusPort {
  private active: LockerBusPort | null = null;
  private activeProfileKey: string | null = null;
  private shouldBeConnected = false;

  constructor(
    private readonly config: ConfigRepositoryPort,
    private readonly factory: LockerBusFactory,
  ) {}

  async connect(): Promise<void> {
    this.shouldBeConnected = true;
    await this.reconcile();
  }

  async disconnect(): Promise<void> {
    this.shouldBeConnected = false;
    await this.active?.disconnect();
  }

  getConnectionState(): ConnectionState {
    return this.active?.getConnectionState() ?? 'disconnected';
  }

  async ensureConnected(): Promise<boolean> {
    if (!this.active) {
      await this.reconcile();
    }
    return (await this.active?.ensureConnected()) ?? false;
  }

  async reloadRuntimeConfig(): Promise<void> {
    await this.reconcile(true);
  }

  flashRelay(target: CompartmentTarget, durationMs: number): Promise<UnlockFeedback> {
    return this.requireActive().flashRelay(target, durationMs);
  }

  readRelayState(target: CompartmentTarget): Promise<boolean> {
    return this.requireActive().readRelayState(target);
  }

  readDoorSensors(slaveId: number, startAddress: number, length: number): Promise<DoorState[]> {
    return this.requireActive().readDoorSensors(slaveId, startAddress, length);
  }

  initializeBoard(slaveId: number): Promise<void> {
    return this.requireActive().initializeBoard(slaveId);
  }

  getConfiguredSlaveIds(): number[] {
    return this.config.getConfiguredSlaveIds();
  }

  private async reconcile(forceReload = false): Promise<void> {
    const profile = this.config.load().hardwareProfile;
    const nextKey = profile ? JSON.stringify(profile) : null;
    if (nextKey === this.activeProfileKey && this.active) {
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
}
