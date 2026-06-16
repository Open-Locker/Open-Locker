"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.RunAfterCompleteScheduler = exports.SystemClock = void 0;
class SystemClock {
    nowIso() {
        return new Date().toISOString();
    }
}
exports.SystemClock = SystemClock;
class RunAfterCompleteScheduler {
    cancelTokens = new Set();
    scheduleAfter(delayMs, fn) {
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
    cancelAll() {
        for (const cancel of this.cancelTokens) {
            cancel();
        }
        this.cancelTokens.clear();
    }
}
exports.RunAfterCompleteScheduler = RunAfterCompleteScheduler;
