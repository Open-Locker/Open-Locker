import PQueue from 'p-queue';
import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import { BusPriority, ConnectionState, LockerBusPort } from '../../ports/locker-bus.port';
import { ReconnectCoordinator } from './reconnect-coordinator';

export interface ModbusDriver {
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  isOpen(): boolean;
  flashRelayOn(slaveId: number, address: number, durationMs: number): Promise<void>;
  readCoils(slaveId: number, address: number, length: number): Promise<boolean[]>;
  readDiscreteInputs(slaveId: number, address: number, length: number): Promise<boolean[]>;
  turnAllRelaysOff(slaveId: number): Promise<void>;
}

export class ModbusBusActor implements LockerBusPort {
  private queue = new PQueue({ concurrency: 1 });
  private connectionState: ConnectionState = 'disconnected';
  private readonly reconnect: ReconnectCoordinator;

  constructor(
    private readonly driver: ModbusDriver,
    reconnectOptions?: { maxAttempts?: number; delayMs?: number },
    private readonly configuredSlaveIds: number[] = [1],
  ) {
    this.reconnect = new ReconnectCoordinator({
      maxAttempts: reconnectOptions?.maxAttempts ?? 0,
      delayMs: reconnectOptions?.delayMs ?? 5000,
    });
  }

  async connect(): Promise<void> {
    return this.run(() => this.connectInternal(), BusPriority.MAINTENANCE);
  }

  async disconnect(): Promise<void> {
    this.reconnect.cancelScheduled();
    this.queue.clear();
    await this.driver.disconnect();
    this.connectionState = 'disconnected';
  }

  getConnectionState(): ConnectionState {
    return this.connectionState;
  }

  getConfiguredSlaveIds(): number[] {
    return [...this.configuredSlaveIds];
  }

  async flashRelay(target: CompartmentTarget, durationMs: number): Promise<void> {
    return this.run(
      () => this.driver.flashRelayOn(target.slaveId, target.relayAddress, durationMs),
      BusPriority.COMMAND,
    );
  }

  async readRelayState(target: CompartmentTarget): Promise<boolean> {
    const values = await this.run(
      () => this.driver.readCoils(target.slaveId, target.relayAddress, 1),
      BusPriority.POLL,
    );
    return values[0] ?? false;
  }

  async readDoorSensor(target: CompartmentTarget): Promise<DoorState> {
    try {
      const values = await this.run(
        () => this.driver.readDiscreteInputs(target.slaveId, target.relayAddress, 1),
        BusPriority.POLL,
      );
      return values[0] ? 'open' : 'closed';
    } catch {
      return 'unknown';
    }
  }

  async turnAllRelaysOff(slaveId: number): Promise<void> {
    return this.run(() => this.driver.turnAllRelaysOff(slaveId), BusPriority.MAINTENANCE);
  }

  async ensureConnected(): Promise<boolean> {
    if (this.driver.isOpen()) {
      return true;
    }

    try {
      await this.reconnect.run(() => this.connectInternal());
      return this.driver.isOpen();
    } catch {
      return false;
    }
  }

  getQueue(): PQueue {
    return this.queue;
  }

  private async connectInternal(): Promise<void> {
    this.connectionState = 'connecting';
    await this.driver.connect();
    this.connectionState = 'connected';
    this.reconnect.resetAttempts();
  }

  private run<T>(operation: () => Promise<T>, priority: BusPriority): Promise<T> {
    return this.queue.add(operation, { priority }) as Promise<T>;
  }
}
