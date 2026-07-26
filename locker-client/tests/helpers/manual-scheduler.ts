import type { SchedulerPort } from '../../src/ports/config.port';

/**
 * Scheduler that queues work instead of using timers, so tests drive detection
 * ticks explicitly rather than waiting on wall-clock delays.
 */
export class ManualScheduler implements SchedulerPort {
  private queue: Array<() => Promise<void>> = [];

  scheduleAfter(_delayMs: number, fn: () => Promise<void>): () => void {
    this.queue.push(fn);

    return () => {
      this.queue = this.queue.filter((queued) => queued !== fn);
    };
  }

  cancelAll(): void {
    this.queue = [];
  }

  /** Run the next queued task, if any. Returns whether one ran. */
  async runNext(): Promise<boolean> {
    const next = this.queue.shift();
    if (!next) {
      return false;
    }

    await next();

    return true;
  }

  /** Run queued tasks until none remain or `limit` iterations elapse. */
  async drain(limit = 100): Promise<void> {
    for (let iteration = 0; iteration < limit; iteration++) {
      if (!(await this.runNext())) {
        return;
      }
    }
  }
}
