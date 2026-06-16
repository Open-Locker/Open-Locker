"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ReconnectCoordinator = void 0;
class ReconnectCoordinator {
    inFlight = null;
    attempts = 0;
    timers = [];
    maxAttempts;
    delayMs;
    constructor(options = {}) {
        this.maxAttempts = options.maxAttempts ?? 0;
        this.delayMs = options.delayMs ?? 5000;
    }
    getAttempts() {
        return this.attempts;
    }
    async run(reconnectFn) {
        if (this.inFlight) {
            return this.inFlight;
        }
        this.inFlight = this.runInternal(reconnectFn).finally(() => {
            this.inFlight = null;
        });
        return this.inFlight;
    }
    resetAttempts() {
        this.attempts = 0;
    }
    cancelScheduled() {
        for (const timer of this.timers) {
            clearTimeout(timer);
        }
        this.timers = [];
    }
    async runInternal(reconnectFn) {
        if (this.maxAttempts > 0 && this.attempts >= this.maxAttempts) {
            throw new Error("Max reconnect attempts reached");
        }
        this.attempts++;
        try {
            await reconnectFn();
            this.attempts = 0;
        }
        catch (error) {
            if (this.maxAttempts === 0 || this.attempts < this.maxAttempts) {
                return this.scheduleRetry(reconnectFn);
            }
            throw error;
        }
    }
    scheduleRetry(reconnectFn) {
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                this.runInternal(reconnectFn).then(resolve).catch(reject);
            }, this.delayMs);
            this.timers.push(timer);
        });
    }
}
exports.ReconnectCoordinator = ReconnectCoordinator;
