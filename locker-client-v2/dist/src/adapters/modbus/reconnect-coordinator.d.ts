export declare class ReconnectCoordinator {
    private inFlight;
    private attempts;
    private timers;
    private readonly maxAttempts;
    private readonly delayMs;
    constructor(options?: {
        maxAttempts?: number;
        delayMs?: number;
    });
    getAttempts(): number;
    run(reconnectFn: () => Promise<void>): Promise<void>;
    resetAttempts(): void;
    cancelScheduled(): void;
    private runInternal;
    private scheduleRetry;
}
