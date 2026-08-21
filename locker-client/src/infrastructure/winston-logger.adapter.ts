import type { LoggerPort } from '../ports/logging.port';
import { logger } from './logging';

/**
 * Shares the application logger rather than building a second one, so trace
 * correlation applies to everything the app logs, not just direct callers.
 */
export function createWinstonLoggerPort(): LoggerPort {
  return {
    warn(message, meta) {
      logger.warn(message, meta);
    },
    error(message, meta) {
      logger.error(message, meta);
    },
  };
}
