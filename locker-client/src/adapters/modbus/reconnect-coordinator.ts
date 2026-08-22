import { noopLogger, type LoggerPort } from '../../ports/logging.port';
import { ModbusTransportError } from '../../domain/errors';

/**
 * Elapsed time, not wall-clock time. A Raspberry Pi has no RTC: it boots with a
 * bogus clock and jumps when NTP first syncs, sometimes backwards. Measuring the
 * cooldown with `Date.now()` would make that difference negative and refuse to
 * recover until wall-clock time caught up — the latch this coordinator exists to
 * remove, through the back door, and most likely at boot, which is exactly when an
 * adapter is most likely to be missing.
 */
function monotonicNow(): number {
  return performance.now();
}

/** How long a spent reconnect cycle waits before another one may start. */
export const DEFAULT_MODBUS_RECONNECT_COOLDOWN_MS = 60_000;

export class ReconnectCoordinator {
  private inFlight: Promise<void> | null = null;
  private attempts = 0;
  private timers: ReturnType<typeof setTimeout>[] = [];
  private cycleSpentAt: number | null = null;
  private readonly maxAttempts: number;
  private readonly delayMs: number;
  private readonly cooldownMs: number;

  constructor(
    options: { maxAttempts?: number; delayMs?: number; cooldownMs?: number } = {},
    private readonly log: LoggerPort = noopLogger,
  ) {
    this.maxAttempts = options.maxAttempts ?? 0;
    this.delayMs = options.delayMs ?? 5000;
    this.cooldownMs = options.cooldownMs ?? DEFAULT_MODBUS_RECONNECT_COOLDOWN_MS;
  }

  /**
   * True once a cycle has been spent and nothing has succeeded since. The caller
   * owns the connection state, so it asks rather than being told.
   */
  isCycleSpent(): boolean {
    return this.cycleSpentAt !== null;
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
    this.cycleSpentAt = null;
  }

  cancelScheduled(): void {
    for (const timer of this.timers) {
      clearTimeout(timer);
    }
    this.timers = [];
  }

  private async runInternal(reconnectFn: () => Promise<void>): Promise<void> {
    if (this.maxAttempts > 0 && this.attempts >= this.maxAttempts) {
      // A budget that cannot be replenished is a latch: the bus would stay
      // unusable long after the outage that spent it had ended. The cooldown is
      // what makes the budget belong to the outage rather than to the process.
      if (this.cycleSpentAt !== null && monotonicNow() - this.cycleSpentAt >= this.cooldownMs) {
        this.log.warn('Modbus reconnect cooldown elapsed, starting a new cycle', {
          previousAttempts: this.attempts,
          cooldownMs: this.cooldownMs,
        });
        this.attempts = 0;
        this.cycleSpentAt = null;
      } else {
        throw new ModbusTransportError('Max reconnect attempts reached');
      }
    }

    this.attempts++;

    try {
      await reconnectFn();
      this.attempts = 0;
      this.cycleSpentAt = null;
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

      // Logged once here, where the cycle ends, rather than on every refusal that
      // follows: a bus down for an hour must not produce an hour of identical
      // error lines, or the one that matters is never noticed.
      this.cycleSpentAt = monotonicNow();
      this.log.error('Modbus reconnect gave up after final attempt', {
        attempts: this.attempts,
        maxAttempts: this.maxAttempts,
        retryAfterMs: this.cooldownMs,
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
