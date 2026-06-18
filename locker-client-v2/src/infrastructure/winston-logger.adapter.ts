import type { LoggerPort } from '../ports/logging.port';
import { createWinstonLogger } from './logger';

export function createWinstonLoggerPort(): LoggerPort {
  const winston = createWinstonLogger();
  return {
    warn(message, meta) {
      winston.warn(message, meta);
    },
  };
}
