export interface LoggerPort {
    warn(message: string, meta?: Record<string, unknown>): void;
}
export declare const noopLogger: LoggerPort;
