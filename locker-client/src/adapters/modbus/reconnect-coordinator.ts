import { noopLogger, type LoggerPort } from '../../ports/logging.port';
import { ModbusTransportError } from '../../domain/errors';

export class ReconnectCoordinator {
  private inFlight: Promise<void> | null = null;
  private attempts = 0;
  private timers: ReturnType<typeof setTimeout>[] = [];
  private readonly maxAttempts: number;
  private readonly delayMs: number;

  constructor(
    options: { maxAttempts?: number; delayMs?: number } = {},
    private readonly log: LoggerPort = noopLogger,
  ) {
    this.maxAttempts = options.maxAttempts ?? 0;
    this.delayMs = options.delayMs ?? 5000;
  }

  getAttempts(): number {
    return this.attempts;
  }

  async run(reconnectFn: () => Promise<void>): Promise<void> {
    if (this.inFlight) {
      return this.inFlight;
    }

    this.inFlight = this.runInternal(reconnectFn).finally(() => {
      this.inFlight = null;
    });

    return this.inFlight;
  }

  resetAttempts(): void {
    this.attempts = 0;
  }

  cancelScheduled(): void {
    for (const timer of this.timers) {
      clearTimeout(timer);
    }
    this.timers = [];
  }

  private async runInternal(reconnectFn: () => Promise<void>): Promise<void> {
    if (this.maxAttempts > 0 && this.attempts >= this.maxAttempts) {
      this.log.error('Modbus reconnect budget already exhausted, refusing further attempts', {
        attempts: this.attempts,
        maxAttempts: this.maxAttempts,
      });
      throw new ModbusTransportError('Max reconnect attempts reached');
    }

    this.attempts++;

    try {
      await reconnectFn();
      this.attempts = 0;
    } catch (error) {
      if (this.maxAttempts === 0 || this.attempts < this.maxAttempts) {
        this.log.warn('Modbus reconnect attempt failed, retrying', {
          attempt: this.attempts,
          maxAttempts: this.maxAttempts,
          retryInMs: this.delayMs,
          error: error instanceof Error ? error.message : String(error),
        });
        return this.scheduleRetry(reconnectFn);
      }

      this.log.error('Modbus reconnect gave up after final attempt', {
        attempts: this.attempts,
        maxAttempts: this.maxAttempts,
        error: error instanceof Error ? error.message : String(error),
      });
      throw error;
    }
  }

  private scheduleRetry(reconnectFn: () => Promise<void>): Promise<void> {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.runInternal(reconnectFn).then(resolve).catch(reject);
      }, this.delayMs);
      this.timers.push(timer);
    });
  }
}
