import PQueue from 'p-queue';
import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import type { HardwareProfile } from '../../domain/config';
import { LockerError, MqttErrorCode } from '../../domain/errors';
import type { ConfigRepositoryPort } from '../../ports/config.port';
import {
  BusPriority,
  type ConnectionState,
  type LockerBusPort,
  type UnlockObservation,
} from '../../ports/locker-bus.port';

export type LockerBusFactory = (profile: HardwareProfile) => LockerBusPort;

export class RuntimeConfiguredLockerBus implements LockerBusPort {
  /**
   * Serializes adapter swaps against bus operations. Priorities pass through so
   * an unlock is not stuck behind polls already waiting here (ADR-0067).
   */
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
    });
  }

  disconnect(): Promise<void> {
    return this.enqueue(async () => {
      this.shouldBeConnected = false;
      await this.active?.disconnect();
      this.active = null;
      this.activeProfileKey = null;
    });
  }

  getConnectionState(): ConnectionState {
    return this.active?.getConnectionState() ?? 'disconnected';
  }

  runExclusive<T>(operation: (bus: LockerBusPort) => Promise<T>): Promise<T> {
    return this.enqueue(async () => {
      await this.reconcile();
      return operation(this.requireActive());
    }, BusPriority.COMMAND);
  }

  ensureConnected(): Promise<boolean> {
    return this.enqueue(async () => {
      if (!this.active) {
        await this.reconcile();
      }
      return (await this.active?.ensureConnected()) ?? false;
    });
  }

  reloadRuntimeConfig(): Promise<void> {
    return this.enqueue(() => this.reconcile(true));
  }

  unlockCompartment(target: CompartmentTarget): Promise<UnlockObservation> {
    return this.enqueue(() => this.requireActive().unlockCompartment(target), BusPriority.COMMAND);
  }

  readCompartmentStates(boardAddress: number, addresses: readonly number[]): Promise<DoorState[]> {
    return this.enqueue(
      () => this.requireActive().readCompartmentStates(boardAddress, addresses),
      BusPriority.SNAPSHOT,
    );
  }

  getConfiguredBoardAddresses(): number[] {
    return this.config.getConfiguredBoardAddresses();
  }

  private async reconcile(forceReload = false): Promise<void> {
    const profile = this.config.load().hardwareProfile;
    const nextKey = profile ? JSON.stringify(profile) : null;
    if (nextKey === this.activeProfileKey && this.active) {
      if (this.shouldBeConnected && this.active.getConnectionState() !== 'connected') {
        await this.active.connect();
        await this.checkConfiguredBoards(this.active);
        return;
      }
      if (forceReload) {
        await this.active.reloadRuntimeConfig();
        await this.checkConfiguredBoards(this.active);
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
      await this.checkConfiguredBoards(next);
    }
  }

  /**
   * A connected bus on which no configured board answers is a wiring or
   * configuration fault; startup and `apply_config` fail loudly on it
   * (ADR-0051, ADR-0067).
   */
  private async checkConfiguredBoards(bus: LockerBusPort): Promise<void> {
    const compartments = this.config.load().compartments ?? [];
    const boardAddresses = this.config.getConfiguredBoardAddresses();
    let answeringBoards = 0;
    for (const boardAddress of boardAddresses) {
      const addresses = compartments
        .filter((compartment) => compartment.slaveId === boardAddress)
        .map((compartment) => compartment.address);
      try {
        await bus.readCompartmentStates(boardAddress, addresses);
        answeringBoards++;
      } catch {
        // Continue so one absent board does not hide the others.
      }
    }
    if (
      boardAddresses.length > 0 &&
      answeringBoards === 0 &&
      bus.getConnectionState() === 'connected'
    ) {
      throw new LockerError(
        MqttErrorCode.HARDWARE_ERROR,
        'No configured board answered on the connected bus',
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

  private enqueue<T>(
    operation: () => Promise<T>,
    priority: BusPriority = BusPriority.MAINTENANCE,
  ): Promise<T> {
    return this.queue.add(operation, { priority }) as Promise<T>;
  }
}
