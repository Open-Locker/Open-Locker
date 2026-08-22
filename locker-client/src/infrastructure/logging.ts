import { createWinstonLogger, type LogTraceContext } from './logger';
import { OpenTelemetryLogTransport } from './otel-log.transport';
import type { LogShippingPort } from '../ports/tracing.port';

/**
 * Bridges log lines to the trace they belong to.
 *
 * The logger is a module singleton used from everywhere, including code with no
 * tracing dependency, so trace context arrives through this hook rather than an
 * injected port. It also keeps OpenTelemetry out of infrastructure entirely,
 * which is what lets a Pi without a collector run without ever loading the SDK.
 */
let traceContextProvider: () => LogTraceContext | undefined = () => undefined;

export function setLogTraceContextProvider(provider: () => LogTraceContext | undefined): void {
  traceContextProvider = provider;
}

export const logger = createWinstonLogger(undefined, () => traceContextProvider());

/**
 * Starts shipping log lines to the collector. Called once at startup, and only
 * when an endpoint is configured — without it the logger behaves exactly as it
 * always has.
 */
export function shipLogsTo(logs: LogShippingPort): void {
  logger.add(new OpenTelemetryLogTransport(logs));
}
