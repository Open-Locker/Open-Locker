import winston from 'winston';

export interface LogTraceContext {
  trace_id: string;
  span_id: string;
}

/**
 * Adds the active trace ids to every line, so a log entry can be taken back to
 * the trace it came from. Yields nothing when tracing is off.
 */
const traceContextFormat = (getTraceContext?: () => LogTraceContext | undefined) =>
  winston.format((info) => {
    const traceContext = getTraceContext?.();

    return traceContext ? { ...info, ...traceContext } : info;
  });

export function createWinstonLogger(
  level = process.env.LOG_LEVEL ?? 'info',
  getTraceContext?: () => LogTraceContext | undefined,
) {
  return winston.createLogger({
    level,
    format: winston.format.combine(
      traceContextFormat(getTraceContext)(),
      winston.format.timestamp(),
      winston.format.json(),
    ),
    transports: [new winston.transports.Console()],
  });
}
