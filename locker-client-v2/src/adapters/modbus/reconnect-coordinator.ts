export class ReconnectCoordinator {
  private inFlight: Promise<void> | null = null;
  private attempts = 0;
  private timers: ReturnType<typeof setTimeout>[] = [];
  private readonly maxAttempts: number;
  private readonly delayMs: number;

  constructor(
    options: { maxAttempts?: number; delayMs?: number } = {},
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
      throw new Error("Max reconnect attempts reached");
    }

    this.attempts++;

    try {
      await reconnectFn();
      this.attempts = 0;
    } catch (error) {
      if (this.maxAttempts === 0 || this.attempts < this.maxAttempts) {
        return this.scheduleRetry(reconnectFn);
      }
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
