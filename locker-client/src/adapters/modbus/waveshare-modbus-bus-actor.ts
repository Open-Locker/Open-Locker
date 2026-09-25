import PQueue from 'p-queue';
import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import type { FeedbackType } from '../../domain/config';
import { doorStateFromFeedbackSignal } from '../../domain/door-feedback-mapping';
import { HardwareTransportError, isReconnectableModbusError } from '../../domain/errors';
import { BusPriority, ConnectionState, LockerBusPort } from '../../ports/locker-bus.port';
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
import { SerialBusConnection } from '../serial/serial-bus-connection';

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
  private readonly connection: SerialBusConnection;

  constructor(
    private readonly driver: WaveshareModbusDriver,
    reconnectOptions?: { maxAttempts?: number; delayMs?: number; cooldownMs?: number },
    /**
     * Accepts a getter so the boards are read when asked for, not captured once.
     * A runtime `apply_config` can add or remove a board, and a snapshot taken
     * at construction would still describe the fleet as it was at boot.
     */
    private readonly configuredSlaveIds: number[] | (() => number[]) = [1],
    private readonly feedbackType: FeedbackType = 'door_closing',
    private readonly tracing: TracingPort = noopTracing,
    private readonly log: LoggerPort = noopLogger,
  ) {
    this.connection = new SerialBusConnection(
      driver,
      {
        maxAttempts: reconnectOptions?.maxAttempts ?? DEFAULT_MODBUS_MAX_RECONNECT_ATTEMPTS,
        delayMs: reconnectOptions?.delayMs ?? 5000,
        cooldownMs: reconnectOptions?.cooldownMs,
      },
      isReconnectableModbusError,
      log,
      'Modbus',
    );
  }

  /**
   * Startup gets the same cycle of attempts as any later drop, and gives up
   * quietly when it is spent: a device whose adapter is missing must still finish
   * starting, so the fault surfaces as an unreachable bus rather than as a boot
   * that never completes or a process that exits.
   */
  async connect(): Promise<void> {
    return this.run(async () => {
      await this.connection.dial();
    }, BusPriority.MAINTENANCE);
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

      return this.connection.dial();
    }, BusPriority.MAINTENANCE);
  }

  async reloadRuntimeConfig(): Promise<void> {
    return this.run(async () => {
      if (!this.driver.isOpen()) {
        await this.connection.dial();
      }
    }, BusPriority.MAINTENANCE);
  }

  async flashRelay(target: CompartmentTarget, durationMs: number): Promise<void> {
    await this.traced(
      'flash_relay',
      {
        [MODBUS_SLAVE_ID]: target.slaveId,
        [MODBUS_ADDRESS]: target.relayAddress,
        [MODBUS_DURATION_MS]: durationMs,
        [COMPARTMENT_NUMBER]: target.compartmentNumber,
      },
      () =>
        this.run(async () => {
          if (!(await this.ensureConnectedInternal())) {
            throw new HardwareTransportError('Cannot open compartment: hardware bus unavailable');
          }

          await this.driver.flashRelayOn(target.slaveId, target.relayAddress, durationMs);
        }, BusPriority.COMMAND),
    );
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
        return typeof value === 'boolean'
          ? doorStateFromFeedbackSignal(this.feedbackType, value)
          : 'unknown';
      });
    } catch (error) {
      this.log.warn('Modbus door sensor read failed, reporting doors as unknown', {
        slaveId,
        startAddress,
        length,
        connectionState: this.connection.getConnectionState(),
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

  private async ensureConnectedInternal(): Promise<boolean> {
    if (this.driver.isOpen()) {
      return true;
    }

    return this.connection.dial();
  }

  private run<T>(operation: () => Promise<T>, priority: BusPriority): Promise<T> {
    return this.queue.add(() => this.connection.runWithReconnectRetry(operation), {
      priority,
    }) as Promise<T>;
  }

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
}
