import PQueue from 'p-queue';
import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import {
  BoardNotRespondingError,
  isReconnectableHardwareError,
  UnlockNotSentError,
} from '../../domain/errors';
import {
  BusPriority,
  type ConnectionState,
  type LockerBusPort,
  type UnlockObservation,
} from '../../ports/locker-bus.port';
import { noopLogger, type LoggerPort } from '../../ports/logging.port';
import { noopTracing, type TracingPort } from '../../ports/tracing.port';
import * as attr from '../../domain/trace-attributes';
import { SerialBusConnection } from '../serial/serial-bus-connection';
import type { Rs485LockBoardDriverPort } from './rs485-lock-board.driver';

export class Rs485LockBoardBusActor implements LockerBusPort {
  private readonly queue = new PQueue({ concurrency: 1 });
  private readonly connection: SerialBusConnection;

  constructor(
    private readonly driver: Rs485LockBoardDriverPort,
    private readonly configuredBoardAddresses: () => number[],
    reconnectOptions?: { maxAttempts?: number; delayMs?: number; cooldownMs?: number },
    private readonly tracing: TracingPort = noopTracing,
    log: LoggerPort = noopLogger,
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

  unlockCompartment(target: CompartmentTarget): Promise<UnlockObservation> {
    return this.tracing.inSpan(
      'rs485 unlock',
      {
        kind: 'internal',
        attributes: {
          [attr.HARDWARE_BOARD_ADDRESS]: target.boardAddress,
          [attr.HARDWARE_COMPARTMENT_ADDRESS]: target.address,
          [attr.COMPARTMENT_NUMBER]: target.compartmentNumber,
        },
      },
      () =>
        this.run(
          () => this.unlockWhenConnected(target),
          BusPriority.COMMAND,
          (error) => error instanceof UnlockNotSentError,
        ),
    );
  }

  async readCompartmentStates(
    boardAddress: number,
    addresses: readonly number[],
  ): Promise<DoorState[]> {
    const states = await this.tracing.inSpan(
      'rs485 query_all',
      { kind: 'internal', attributes: { [attr.HARDWARE_BOARD_ADDRESS]: boardAddress } },
      () =>
        this.run(
          () => this.driver.queryAll(boardAddress),
          BusPriority.SNAPSHOT,
          // A silent board is not asked again straight away: the reconnect
          // still runs, and the next poll retries, so an unlock waits at most
          // one response timeout behind it.
          (error) => !(error instanceof BoardNotRespondingError),
        ),
    );
    return addresses.map((address) => states[address] ?? 'unknown');
  }

  getConfiguredBoardAddresses(): number[] {
    return [...this.configuredBoardAddresses()];
  }

  private async unlockWhenConnected(target: CompartmentTarget): Promise<UnlockObservation> {
    if (!this.driver.isOpen() && !(await this.connection.dial())) {
      throw new UnlockNotSentError('Cannot open compartment: hardware bus unavailable');
    }

    return { doorState: await this.driver.unlock(target.boardAddress, target.address) };
  }

  private run<T>(
    operation: () => Promise<T>,
    priority: BusPriority,
    rerunAfterReconnect?: (error: unknown) => boolean,
  ): Promise<T> {
    return this.queue.add(
      () => this.connection.runWithReconnectRetry(operation, rerunAfterReconnect),
      { priority },
    ) as Promise<T>;
  }
}
