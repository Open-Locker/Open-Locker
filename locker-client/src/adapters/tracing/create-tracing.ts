import { noopTracing, type LogShippingPort, type TracingPort } from '../../ports/tracing.port';
import type { LoggerPort } from '../../ports/logging.port';

export const DEFAULT_SERVICE_NAME = 'open-locker-client';

export interface CreateTracingOptions {
  /** Locker UUID, used as `service.instance.id`. */
  lockerUuid: string;
  env?: NodeJS.ProcessEnv;
  /** Where the SDK's own export failures are reported. */
  log?: LoggerPort;
}

/**
 * Tracing is off unless a collector endpoint is configured.
 *
 * The OpenTelemetry adapter is imported lazily, so a Pi running without a
 * collector never loads the SDK — it pays no startup, memory, or batching cost
 * for a feature it is not using.
 */
export async function createTracing(
  options: CreateTracingOptions,
): Promise<TracingPort & LogShippingPort> {
  const env = options.env ?? process.env;

  const endpoint = env.OTEL_EXPORTER_OTLP_ENDPOINT?.trim();
  const disabled = env.OTEL_SDK_DISABLED?.trim().toLowerCase() === 'true';

  if (!endpoint || disabled) {
    return noopTracing;
  }

  const { OtelTracingAdapter } = await import('./otel-tracing.adapter');

  return new OtelTracingAdapter({
    serviceName: env.OTEL_SERVICE_NAME?.trim() || DEFAULT_SERVICE_NAME,
    serviceInstanceId: options.lockerUuid,
    serviceVersion: env.OTEL_SERVICE_VERSION?.trim() || undefined,
    endpoint,
    log: options.log,
  });
}
