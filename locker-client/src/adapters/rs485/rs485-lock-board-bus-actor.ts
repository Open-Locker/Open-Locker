import PQueue from 'p-queue';
import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import { HardwareTransportError } from '../../domain/errors';
import {
  BusPriority,
  type ConnectionState,
  type LockerBusPort,
  type UnlockFeedback,
} from '../../ports/locker-bus.port';
import { noopLogger, type LoggerPort } from '../../ports/logging.port';
import { noopTracing, type TracingPort } from '../../ports/tracing.port';
import { ReconnectCoordinator } from '../modbus/reconnect-coordinator';
import type { Rs485LockBoardDriverPort } from './rs485-lock-board.driver';

export class Rs485LockBoardBusActor implements LockerBusPort {
  private readonly queue = new PQueue({ concurrency: 1 });
  private readonly reconnect: ReconnectCoordinator;
  private connectionState: ConnectionState = 'disconnected';

  constructor(
    private readonly driver: Rs485LockBoardDriverPort,
    private readonly configuredSlaveIds: () => number[],
    reconnectOptions?: { maxAttempts?: number; delayMs?: number },
    private readonly tracing: TracingPort = noopTracing,
    private readonly log: LoggerPort = noopLogger,
  ) {
    this.reconnect = new ReconnectCoordinator(
      {
        maxAttempts: reconnectOptions?.maxAttempts ?? 5,
        delayMs: reconnectOptions?.delayMs ?? 5000,
      },
      log,
    );
  }

  connect(): Promise<void> {
    return this.run(() => this.connectInternal(), BusPriority.MAINTENANCE);
  }

  async disconnect(): Promise<void> {
    this.reconnect.cancelScheduled();
    await this.queue.onIdle();
    await this.driver.disconnect();
    this.connectionState = 'disconnected';
  }

  getConnectionState(): ConnectionState {
    return this.connectionState;
  }

  runExclusive<T>(operation: (bus: LockerBusPort) => Promise<T>): Promise<T> {
    return operation(this);
  }

  async ensureConnected(): Promise<boolean> {
    return this.run(async () => {
      if (this.driver.isOpen()) {
        return true;
      }
      try {
        await this.reconnect.run(() => this.connectInternal());
        return this.driver.isOpen();
      } catch (error) {
        this.log.error('RS485 lock board bus unreachable after reconnect attempts', {
          error: error instanceof Error ? error.message : String(error),
        });
        return false;
      }
    }, BusPriority.MAINTENANCE);
  }

  async reloadRuntimeConfig(): Promise<void> {
    if (!(await this.ensureConnected())) {
      throw new HardwareTransportError('RS485 lock board bus is unavailable', true);
    }
  }

  flashRelay(target: CompartmentTarget, _durationMs: number): Promise<UnlockFeedback> {
    return this.tracing.inSpan(
      'rs485 unlock',
      {
        kind: 'internal',
        attributes: {
          'locker.rs485.board_address': target.slaveId,
          'locker.rs485.channel': target.relayAddress,
          'locker.compartment.number': target.compartmentNumber,
        },
      },
      () =>
        this.run(
          () => this.driver.unlock(target.slaveId, target.relayAddress),
          BusPriority.COMMAND,
        ),
    );
  }

  async readRelayState(_target: CompartmentTarget): Promise<boolean> {
    return false;
  }

  async readDoorSensors(
    slaveId: number,
    startAddress: number,
    length: number,
  ): Promise<DoorState[]> {
    try {
      const states = await this.run(() => this.driver.queryAll(slaveId), BusPriority.SNAPSHOT);
      return states.slice(startAddress, startAddress + length);
    } catch (error) {
      this.log.warn('RS485 lock board status query failed, reporting doors as unknown', {
        slaveId,
        startAddress,
        length,
        error: error instanceof Error ? error.message : String(error),
      });
      return Array.from({ length }, () => 'unknown');
    }
  }

  async initializeBoard(slaveId: number): Promise<void> {
    await this.run(() => this.driver.queryAll(slaveId), BusPriority.MAINTENANCE);
  }

  getConfiguredSlaveIds(): number[] {
    return [...this.configuredSlaveIds()];
  }

  private async connectInternal(): Promise<void> {
    this.connectionState = 'connecting';
    try {
      await this.driver.connect();
      this.connectionState = 'connected';
      this.reconnect.resetAttempts();
    } catch (error) {
      this.connectionState = 'disconnected';
      throw error;
    }
  }

  private run<T>(operation: () => Promise<T>, priority: BusPriority): Promise<T> {
    return this.queue.add(async () => this.runWithReconnectRetry(operation), {
      priority,
    }) as Promise<T>;
  }

  private async runWithReconnectRetry<T>(operation: () => Promise<T>): Promise<T> {
    try {
      return await operation();
    } catch (error) {
      if (!(error instanceof HardwareTransportError) || !error.reconnectable) {
        throw error;
      }
      await this.driver.disconnect();
      this.connectionState = 'disconnected';
      await this.reconnect.run(() => this.connectInternal());
      return operation();
    }
  }
}
