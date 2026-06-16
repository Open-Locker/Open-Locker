import type { ClockPort, SchedulerPort } from '../ports/config.port';
export declare class SystemClock implements ClockPort {
    nowIso(): string;
}
export declare class RunAfterCompleteScheduler implements SchedulerPort {
    private cancelTokens;
    scheduleAfter(delayMs: number, fn: () => Promise<void>): () => void;
    cancelAll(): void;
}
