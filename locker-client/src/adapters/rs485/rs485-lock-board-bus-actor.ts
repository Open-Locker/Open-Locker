import PQueue from 'p-queue';
import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import { isReconnectableHardwareError } from '../../domain/errors';
import { BusPriority, type ConnectionState, type LockerBusPort } from '../../ports/locker-bus.port';
import { noopLogger, type LoggerPort } from '../../ports/logging.port';
import { noopTracing, type TracingPort } from '../../ports/tracing.port';
import { SerialBusConnection } from '../serial/serial-bus-connection';
import type { Rs485LockBoardDriverPort } from './rs485-lock-board.driver';

export class Rs485LockBoardBusActor implements LockerBusPort {
  private readonly queue = new PQueue({ concurrency: 1 });
  private readonly connection: SerialBusConnection;

  constructor(
    private readonly driver: Rs485LockBoardDriverPort,
    private readonly configuredSlaveIds: () => number[],
    reconnectOptions?: { maxAttempts?: number; delayMs?: number; cooldownMs?: number },
    private readonly tracing: TracingPort = noopTracing,
    private readonly log: LoggerPort = noopLogger,
  ) {
    this.connection = new SerialBusConnection(
      driver,
      {
        maxAttempts: reconnectOptions?.maxAttempts ?? 5,
        delayMs: reconnectOptions?.delayMs ?? 5000,
        cooldownMs: reconnectOptions?.cooldownMs,
      },
      isReconnectableHardwareError,
      log,
      'RS485 lock board',
    );
  }

  connect(): Promise<void> {
    return this.run(() => this.connection.dial().then(() => undefined), BusPriority.MAINTENANCE);
  }

  async disconnect(): Promise<void> {
    this.connection.cancelScheduledReconnect();
    await this.queue.onIdle();
    await this.connection.disconnect();
  }

  getConnectionState(): ConnectionState {
    return this.connection.getConnectionState();
  }

  runExclusive<T>(operation: (bus: LockerBusPort) => Promise<T>): Promise<T> {
    return operation(this);
  }

  async ensureConnected(): Promise<boolean> {
    return this.run(() => {
      if (this.driver.isOpen()) {
        return Promise.resolve(true);
      }
      return this.connection.dial();
    }, BusPriority.MAINTENANCE);
  }

  async reloadRuntimeConfig(): Promise<void> {
    await this.run(async () => {
      if (!this.driver.isOpen()) {
        await this.connection.dial();
      }
    }, BusPriority.MAINTENANCE);
  }

  flashRelay(target: CompartmentTarget, _durationMs: number): Promise<void> {
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

  async readDoorSensors(
    slaveId: number,
    startAddress: number,
    length: number,
  ): Promise<DoorState[]> {
    try {
      const states = await this.run(() => this.driver.queryAll(slaveId), BusPriority.SNAPSHOT);
      return Array.from({ length }, (_, offset) => {
        const index = startAddress + offset;
        return states[index] ?? 'unknown';
      });
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

  private run<T>(operation: () => Promise<T>, priority: BusPriority): Promise<T> {
    return this.queue.add(() => this.connection.runWithReconnectRetry(operation), {
      priority,
    }) as Promise<T>;
  }
}
