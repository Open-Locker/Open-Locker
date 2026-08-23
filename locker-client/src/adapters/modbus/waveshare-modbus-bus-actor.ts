import PQueue from 'p-queue';
import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import { isReconnectableModbusError } from '../../domain/errors';
import {
  BusPriority,
  ConnectionState,
  LockerBusPort,
  type UnlockFeedback,
} from '../../ports/locker-bus.port';
import { noopLogger, type LoggerPort } from '../../ports/logging.port';
import { noopTracing, type SpanAttributes, type TracingPort } from '../../ports/tracing.port';
import {
  COMPARTMENT_NUMBER,
  MODBUS_ADDRESS,
  MODBUS_DURATION_MS,
  MODBUS_LENGTH,
  MODBUS_OPERATION,
  MODBUS_SLAVE_ID,
} from '../../domain/trace-attributes';
import { ReconnectCoordinator } from './reconnect-coordinator';

/** Matches v1 `modbusService.maxReconnectAttempts`. */
export const DEFAULT_MODBUS_MAX_RECONNECT_ATTEMPTS = 5;

export interface WaveshareModbusDriver {
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  isOpen(): boolean;
  flashRelayOn(slaveId: number, address: number, durationMs: number): Promise<void>;
  readCoils(slaveId: number, address: number, length: number): Promise<boolean[]>;
  readDiscreteInputs(slaveId: number, address: number, length: number): Promise<boolean[]>;
  turnAllRelaysOff(slaveId: number): Promise<void>;
}

export class WaveshareModbusBusActor implements LockerBusPort {
  private queue = new PQueue({ concurrency: 1 });
  private connectionState: ConnectionState = 'disconnected';
  private readonly reconnect: ReconnectCoordinator;

  constructor(
    private readonly driver: WaveshareModbusDriver,
    reconnectOptions?: { maxAttempts?: number; delayMs?: number },
    /**
     * Accepts a getter so the boards are read when asked for, not captured once.
     * A runtime `apply_config` can add or remove a board, and a snapshot taken
     * at construction would still describe the fleet as it was at boot.
     */
    private readonly configuredSlaveIds: number[] | (() => number[]) = [1],
    private readonly tracing: TracingPort = noopTracing,
    private readonly log: LoggerPort = noopLogger,
  ) {
    this.reconnect = new ReconnectCoordinator(
      {
        maxAttempts: reconnectOptions?.maxAttempts ?? DEFAULT_MODBUS_MAX_RECONNECT_ATTEMPTS,
        delayMs: reconnectOptions?.delayMs ?? 5000,
      },
      log,
    );
  }

  async connect(): Promise<void> {
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

  getConfiguredSlaveIds(): number[] {
    return typeof this.configuredSlaveIds === 'function'
      ? [...this.configuredSlaveIds()]
      : [...this.configuredSlaveIds];
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
        // Callers only see false, so without this line an unreachable bus looks
        // identical to a bus that is merely busy.
        this.log.error('Modbus bus unreachable after reconnect attempts', {
          attempts: this.reconnect.getAttempts(),
          connectionState: this.connectionState,
          error: error instanceof Error ? error.message : String(error),
        });
        return false;
      }
    }, BusPriority.MAINTENANCE);
  }

  async reloadRuntimeConfig(): Promise<void> {
    return this.run(async () => {
      if (!this.driver.isOpen()) {
        await this.connectInternal();
      }
    }, BusPriority.MAINTENANCE);
  }

  async flashRelay(target: CompartmentTarget, durationMs: number): Promise<UnlockFeedback> {
    await this.traced(
      'flash_relay',
      {
        [MODBUS_SLAVE_ID]: target.slaveId,
        [MODBUS_ADDRESS]: target.relayAddress,
        [MODBUS_DURATION_MS]: durationMs,
        // Ties the electrical write back to the compartment a user asked for.
        [COMPARTMENT_NUMBER]: target.compartmentNumber,
      },
      () =>
        this.run(
          () => this.driver.flashRelayOn(target.slaveId, target.relayAddress, durationMs),
          BusPriority.COMMAND,
        ),
    );
    return 'pulse_sent';
  }

  async readRelayState(target: CompartmentTarget): Promise<boolean> {
    const values = await this.traced(
      'read_coils',
      { [MODBUS_SLAVE_ID]: target.slaveId, [MODBUS_ADDRESS]: target.relayAddress },
      () =>
        this.run(
          () => this.driver.readCoils(target.slaveId, target.relayAddress, 1),
          BusPriority.POLL,
        ),
    );
    return values[0] ?? false;
  }

  async readDoorSensors(
    slaveId: number,
    startAddress: number,
    length: number,
  ): Promise<DoorState[]> {
    try {
      const values = await this.traced(
        'read_discrete_inputs',
        {
          [MODBUS_SLAVE_ID]: slaveId,
          [MODBUS_ADDRESS]: startAddress,
          [MODBUS_LENGTH]: length,
        },
        () =>
          this.run(
            () => this.driver.readDiscreteInputs(slaveId, startAddress, length),
            BusPriority.SNAPSHOT,
          ),
      );

      return Array.from({ length }, (_, offset) => {
        const value = values[offset];
        return typeof value === 'boolean' ? (value ? 'closed' : 'open') : 'unknown';
      });
    } catch (error) {
      // An unreachable board reports unknown doors rather than failing. The span
      // records the timeout, but traces are sampled and may be off entirely, so
      // the reason a whole board went unknown is logged here too. This is the
      // layer that owns hardware reporting: callers above only ever see the
      // substituted 'unknown' values.
      this.log.warn('Modbus door sensor read failed, reporting doors as unknown', {
        slaveId,
        startAddress,
        length,
        connectionState: this.connectionState,
        error: error instanceof Error ? error.message : String(error),
      });
      return Array.from({ length }, () => 'unknown');
    }
  }

  async initializeBoard(slaveId: number): Promise<void> {
    return this.traced('turn_all_relays_off', { [MODBUS_SLAVE_ID]: slaveId }, () =>
      this.run(() => this.driver.turnAllRelaysOff(slaveId), BusPriority.MAINTENANCE),
    );
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
    return this.queue.add(async () => this.runWithReconnectRetry(operation), {
      priority,
    }) as Promise<T>;
  }

  /**
   * Wraps a bus operation in a span.
   *
   * The span starts before the operation is queued, so the time a write spends
   * waiting behind other traffic is inside it. On this bus that wait is often
   * the reason a door felt slow, and hiding it would make the trace misleading.
   */
  private traced<T>(
    operation: string,
    attributes: SpanAttributes,
    fn: () => Promise<T>,
  ): Promise<T> {
    return this.tracing.inSpan(
      `modbus ${operation}`,
      { kind: 'internal', attributes: { ...attributes, [MODBUS_OPERATION]: operation } },
      () => fn(),
    );
  }

  private async runWithReconnectRetry<T>(operation: () => Promise<T>): Promise<T> {
    try {
      return await operation();
    } catch (error) {
      if (!isReconnectableModbusError(error)) {
        throw error;
      }

      await this.driver.disconnect();
      this.connectionState = 'disconnected';
      await this.reconnect.run(() => this.connectInternal());
      return operation();
    }
  }
}
