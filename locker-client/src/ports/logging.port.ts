export interface LoggerPort {
  warn(message: string, meta?: Record<string, unknown>): void;
}

export const noopLogger: LoggerPort = {
  warn() {},
};
