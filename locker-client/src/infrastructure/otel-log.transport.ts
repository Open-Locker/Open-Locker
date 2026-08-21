import Transport from 'winston-transport';
import type { LogShippingPort, SpanAttributes } from '../ports/tracing.port';

/**
 * Forwards winston output to the collector, so the client's logs and errors
 * land in the same dashboard as its traces.
 *
 * Added alongside the console transport rather than replacing it: `docker logs`
 * on the Pi must keep working when no collector is reachable.
 */
export class OpenTelemetryLogTransport extends Transport {
  constructor(private readonly logs: LogShippingPort) {
    super();
  }

  log(info: Record<string, unknown>, next: () => void): void {
    // `timestamp` and `splat` are winston's own bookkeeping: the collector
    // stamps its own time, and splat is formatting state, not context.
    const { level, message, timestamp: _timestamp, splat: _splat, ...rest } = info;

    this.logs.emitLog({
      level: typeof level === 'string' ? level : 'info',
      message: typeof message === 'string' ? message : JSON.stringify(message),
      attributes: toAttributes(rest),
    });

    next();
  }
}

/**
 * Log context is arbitrary, but span attributes are scalars. Anything richer is
 * serialised rather than dropped, so nothing silently disappears from a log
 * line that was useful on the console.
 */
function toAttributes(context: Record<string, unknown>): SpanAttributes {
  const attributes: SpanAttributes = {};

  for (const [key, value] of Object.entries(context)) {
    if (value === undefined || value === null) {
      continue;
    }

    attributes[key] =
      typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
        ? value
        : JSON.stringify(value);
  }

  return attributes;
}
