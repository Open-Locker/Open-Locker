import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-http';
import { OTLPLogExporter } from '@opentelemetry/exporter-logs-otlp-http';
import {
  BatchLogRecordProcessor,
  LoggerProvider,
  type LogRecordProcessor,
} from '@opentelemetry/sdk-logs';
import { SeverityNumber, type Logger as OtelLogger } from '@opentelemetry/api-logs';
import { W3CTraceContextPropagator } from '@opentelemetry/core';
import { resourceFromAttributes } from '@opentelemetry/resources';
import { BatchSpanProcessor, type SpanProcessor } from '@opentelemetry/sdk-trace-base';
import { NodeTracerProvider } from '@opentelemetry/sdk-trace-node';
import { ATTR_SERVICE_NAME, ATTR_SERVICE_VERSION } from '@opentelemetry/semantic-conventions';
import {
  context,
  diag,
  DiagLogLevel,
  SpanKind,
  SpanStatusCode,
  trace,
  type Attributes,
  type Context,
  type Span,
  type Tracer,
} from '@opentelemetry/api';
import { noopLogger, type LoggerPort } from '../../ports/logging.port';
import type {
  ActiveSpan,
  LogShippingPort,
  SpanAttributes,
  SpanOptions,
  TelemetryLogRecord,
  TraceCorrelation,
  TraceSpanKind,
  TracingPort,
} from '../../ports/tracing.port';

export interface OtelTracingOptions {
  serviceName: string;
  /** Locker UUID: every Pi reports under one service name, split by instance. */
  serviceInstanceId: string;
  serviceVersion?: string;
  /** OTLP/HTTP base endpoint, e.g. http://otel-collector:4318 */
  endpoint: string;
  /** Where the SDK's own diagnostics go. Defaults to discarding them. */
  log?: LoggerPort;
  /**
   * Replaces the OTLP exporter pipeline. Tests use it to keep spans in memory;
   * production leaves it unset.
   */
  spanProcessors?: SpanProcessor[];
  /** Same idea as `spanProcessors`, for log records. */
  logProcessors?: LogRecordProcessor[];
}

const SERVICE_INSTANCE_ID = 'service.instance.id';

/** Winston's levels mapped onto the OpenTelemetry severity scale. */
const severityNumbers: Record<string, SeverityNumber> = {
  error: SeverityNumber.ERROR,
  warn: SeverityNumber.WARN,
  info: SeverityNumber.INFO,
  http: SeverityNumber.DEBUG,
  verbose: SeverityNumber.DEBUG,
  debug: SeverityNumber.DEBUG,
  silly: SeverityNumber.TRACE,
};

const spanKinds: Record<TraceSpanKind, SpanKind> = {
  producer: SpanKind.PRODUCER,
  consumer: SpanKind.CONSUMER,
  internal: SpanKind.INTERNAL,
};

/** How long an identical exporter complaint stays suppressed. */
const DIAGNOSTIC_REPEAT_WINDOW_MS = 5 * 60 * 1000;

/**
 * Cap on distinct complaints remembered. Reached only by messages that embed
 * identifiers, which normalisation should already have collapsed — the cap is
 * there so an unforeseen shape cannot grow without bound on a Pi that runs for
 * months.
 */
const DIAGNOSTIC_KEY_LIMIT = 256;

/**
 * Collapses the parts of a diagnostic that differ per occurrence.
 *
 * The SDK embeds trace and span ids in some warnings ("Cannot execute the
 * operation on ended Span {traceId: …}"), which would make every occurrence a
 * distinct key and defeat the throttle entirely.
 */
function throttleKey(message: string): string {
  return message.replace(/\b[0-9a-f]{16,32}\b/gi, '<id>').slice(0, 200);
}

/**
 * Routes OpenTelemetry's own diagnostics through the app logger, once per
 * distinct message per window.
 *
 * A collector that is configured but unreachable — a flaky site link, a
 * collector that moved — retries indefinitely, and each failure prints. On a Pi
 * that accumulates for as long as the endpoint stays down, which makes the
 * telemetry noisier than the flow it exists to observe. Losing the repeats
 * costs nothing: the hundredth identical failure says what the first did.
 *
 * The first occurrence is always reported, so a broken exporter is still
 * visible rather than silently swallowed.
 *
 * Note that `diag.setLogger` is process-global while this runs from a per-device
 * constructor: a simulated fleet re-registers it once per bank, each replacing
 * the previous throttle window. Harmless — the last registration wins and the
 * behaviour is identical — but it is why the override warning is suppressed.
 */
function quietenExporterDiagnostics(log: LoggerPort): void {
  const lastReportedAt = new Map<string, number>();

  const reportOnce = (message: string): void => {
    const key = throttleKey(message);
    const previous = lastReportedAt.get(key);
    const now = Date.now();

    if (previous !== undefined && now - previous < DIAGNOSTIC_REPEAT_WINDOW_MS) {
      return;
    }

    if (lastReportedAt.size >= DIAGNOSTIC_KEY_LIMIT) {
      const oldest = lastReportedAt.keys().next().value;

      if (oldest !== undefined) {
        lastReportedAt.delete(oldest);
      }
    }

    lastReportedAt.set(key, now);
    log.warn('OpenTelemetry exporter reported a failure', { message });
  };

  diag.setLogger(
    {
      error: (message) => reportOnce(message),
      warn: (message) => reportOnce(message),
      info: () => undefined,
      debug: () => undefined,
      verbose: () => undefined,
    },
    { logLevel: DiagLogLevel.WARN, suppressOverrideMessage: true },
  );
}

/**
 * OpenTelemetry implementation of {@link TracingPort}.
 *
 * Constructed only when an endpoint is configured — see `createTracing` — so
 * importing this module is itself the opt-in.
 */
export class OtelTracingAdapter implements TracingPort, LogShippingPort {
  private readonly provider: NodeTracerProvider;
  private readonly loggerProvider: LoggerProvider;
  private readonly otelLogger: OtelLogger;
  private readonly tracer: Tracer;
  private readonly propagator = new W3CTraceContextPropagator();

  constructor(options: OtelTracingOptions) {
    quietenExporterDiagnostics(options.log ?? noopLogger);

    const resource = resourceFromAttributes({
      [ATTR_SERVICE_NAME]: options.serviceName,
      [SERVICE_INSTANCE_ID]: options.serviceInstanceId,
      ...(options.serviceVersion ? { [ATTR_SERVICE_VERSION]: options.serviceVersion } : {}),
    });

    const endpoint = options.endpoint.replace(/\/$/, '');

    this.loggerProvider = new LoggerProvider({
      resource,
      processors: options.logProcessors ?? [
        new BatchLogRecordProcessor({
          exporter: new OTLPLogExporter({ url: `${endpoint}/v1/logs` }),
        }),
      ],
    });
    this.otelLogger = this.loggerProvider.getLogger(options.serviceName);

    this.provider = new NodeTracerProvider({
      resource,
      spanProcessors: options.spanProcessors ?? [
        new BatchSpanProcessor(new OTLPTraceExporter({ url: `${endpoint}/v1/traces` })),
      ],
    });

    // Registers the async context manager, which is what lets a span stay
    // current across awaits. Global registration is first-wins in the OTel API,
    // so the tracer is taken from our own provider rather than the global one —
    // otherwise a second adapter in the same process would silently emit into
    // the first one's pipeline.
    this.provider.register({ propagator: this.propagator });
    this.tracer = this.provider.getTracer(options.serviceName);
  }

  async inSpan<T>(
    name: string,
    options: SpanOptions,
    fn: (span: ActiveSpan) => Promise<T>,
  ): Promise<T> {
    const parent = this.parentContext(options.parentTraceparent);

    return this.tracer.startActiveSpan(
      name,
      {
        kind: spanKinds[options.kind ?? 'internal'],
        attributes: toOtelAttributes(options.attributes),
      },
      parent,
      async (span: Span) => {
        try {
          return await fn(wrapSpan(span));
        } catch (error) {
          span.setStatus({ code: SpanStatusCode.ERROR });
          span.recordException(error instanceof Error ? error : new Error(String(error)));
          throw error;
        } finally {
          span.end();
        }
      },
    );
  }

  currentTraceparent(): string | undefined {
    const carrier: Record<string, string> = {};

    this.propagator.inject(context.active(), carrier, {
      set: (target, key, value) => {
        target[key] = value as string;
      },
    });

    return carrier.traceparent;
  }

  currentCorrelation(): TraceCorrelation | undefined {
    const spanContext = trace.getSpan(context.active())?.spanContext();

    if (!spanContext) {
      return undefined;
    }

    return { trace_id: spanContext.traceId, span_id: spanContext.spanId };
  }

  /**
   * The active span is picked up from context automatically, so a log written
   * while handling a command is linked to that command's trace.
   */
  emitLog(record: TelemetryLogRecord): void {
    this.otelLogger.emit({
      severityText: record.level.toUpperCase(),
      severityNumber: severityNumbers[record.level] ?? SeverityNumber.INFO,
      body: record.message,
      attributes: toOtelAttributes(record.attributes),
    });
  }

  async shutdown(): Promise<void> {
    await this.provider.shutdown();
    await this.loggerProvider.shutdown();
  }

  /**
   * A malformed `traceparent` extracts to a context without a valid span, which
   * we discard so the span becomes a root instead of a child of nothing.
   */
  private parentContext(traceparent?: string): Context {
    const active = context.active();

    if (!traceparent) {
      return active;
    }

    const extracted = this.propagator.extract(
      active,
      { traceparent },
      {
        get: (carrier, key) => (carrier as Record<string, string>)[key],
        keys: (carrier) => Object.keys(carrier as Record<string, string>),
      },
    );

    return trace.getSpan(extracted)?.spanContext() ? extracted : active;
  }
}

function wrapSpan(span: Span): ActiveSpan {
  return {
    setAttributes(attributes: SpanAttributes) {
      span.setAttributes(toOtelAttributes(attributes));
    },
    recordFailure(error: unknown) {
      span.setStatus({ code: SpanStatusCode.ERROR });
      span.recordException(error instanceof Error ? error : new Error(String(error)));
    },
  };
}

/** Drops undefined values so absent facts do not become empty attributes. */
function toOtelAttributes(attributes?: SpanAttributes): Attributes {
  if (!attributes) {
    return {};
  }

  const result: Attributes = {};

  for (const [key, value] of Object.entries(attributes)) {
    if (value !== undefined) {
      result[key] = value;
    }
  }

  return result;
}
