import type { ClockPort, SchedulerPort } from '../ports/config.port';

export class SystemClock implements ClockPort {
  nowIso(): string {
    return new Date().toISOString();
  }
}

export class RunAfterCompleteScheduler implements SchedulerPort {
  private cancelTokens = new Set<() => void>();

  scheduleAfter(delayMs: number, fn: () => Promise<void>): () => void {
    let cancelled = false;
    const timeout = setTimeout(() => {
      if (!cancelled) {
        void fn();
      }
    }, delayMs);

    const cancel = () => {
      cancelled = true;
      clearTimeout(timeout);
      this.cancelTokens.delete(cancel);
    };

    this.cancelTokens.add(cancel);
    return cancel;
  }

  cancelAll(): void {
    for (const cancel of this.cancelTokens) {
      cancel();
    }
    this.cancelTokens.clear();
  }
}
