import type { ConnectionState } from '../../ports/locker-bus.port';
import { noopLogger, type LoggerPort } from '../../ports/logging.port';
import { ReconnectCoordinator } from '../modbus/reconnect-coordinator';

export interface SerialPortLifecycle {
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  isOpen(): boolean;
}

export class SerialBusConnection {
  private connectionState: ConnectionState = 'disconnected';
  private readonly reconnect: ReconnectCoordinator;

  constructor(
    private readonly driver: SerialPortLifecycle,
    reconnectOptions: { maxAttempts?: number; delayMs?: number; cooldownMs?: number } | undefined,
    private readonly isReconnectableError: (error: unknown) => boolean,
    private readonly log: LoggerPort = noopLogger,
    private readonly busLabel = 'Serial',
  ) {
    this.reconnect = new ReconnectCoordinator(reconnectOptions, log);
  }

  getConnectionState(): ConnectionState {
    return this.connectionState;
  }

  cancelScheduledReconnect(): void {
    this.reconnect.cancelScheduled();
  }

  async disconnect(): Promise<void> {
    this.reconnect.cancelScheduled();
    await this.driver.disconnect();
    this.connectionState = 'disconnected';
  }

  async dial(): Promise<boolean> {
    try {
      await this.reconnect.run(() => this.connectInternal(), this.reconnectOptions());
      return this.driver.isOpen();
    } catch (error) {
      if (this.isReconnectableError(error)) {
        this.markUnreachable(error);
      } else {
        this.connectionState = 'disconnected';
      }
      return false;
    }
  }

  async runWithReconnectRetry<T>(operation: () => Promise<T>): Promise<T> {
    try {
      return await operation();
    } catch (error) {
      if (!this.isReconnectableError(error)) {
        throw error;
      }

      await this.driver.disconnect();
      this.connectionState = 'disconnected';

      try {
        await this.reconnect.run(() => this.connectInternal(), this.reconnectOptions());
      } catch (reconnectError) {
        this.markUnreachable(reconnectError);
        throw reconnectError;
      }

      return operation();
    }
  }

  private async connectInternal(): Promise<void> {
    this.connectionState = 'connecting';
    await this.driver.connect();
    this.connectionState = 'connected';
    this.reconnect.resetAttempts();
  }

  private reconnectOptions(): { isReconnectable: (error: unknown) => boolean } {
    return { isReconnectable: (error) => this.isReconnectableError(error) };
  }

  private markUnreachable(error: unknown): void {
    if (!this.reconnect.isCycleSpent()) {
      return;
    }

    this.connectionState = 'unreachable';
    this.log.error(`${this.busLabel} bus unreachable after reconnect attempts`, {
      attempts: this.reconnect.getAttempts(),
      connectionState: this.connectionState,
      error: error instanceof Error ? error.message : String(error),
    });
  }
}
