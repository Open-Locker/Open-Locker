/**
 * Tracing seam.
 *
 * Application and adapter code depends on this port, never on OpenTelemetry
 * directly, so the SDK stays out of the Pi's hot path unless a collector is
 * actually configured. The default implementation below does nothing at all.
 */

export type SpanAttributeValue = string | number | boolean | undefined;

export type SpanAttributes = Record<string, SpanAttributeValue>;

/**
 * Only the kinds this client emits. `producer`/`consumer` mark the two ends of
 * the MQTT hop; Modbus work is `internal`.
 */
export type TraceSpanKind = 'producer' | 'consumer' | 'internal';

export interface SpanOptions {
  kind?: TraceSpanKind;
  attributes?: SpanAttributes;
  /**
   * W3C `traceparent` from an inbound message. When absent the span starts a
   * new trace; when malformed it is ignored rather than throwing, because a
   * broken header must never cost us the message.
   */
  parentTraceparent?: string;
}

export interface ActiveSpan {
  setAttributes(attributes: SpanAttributes): void;
  /** Marks the span failed. The error is not attached as an attribute. */
  recordFailure(error: unknown): void;
}

export interface TraceCorrelation {
  trace_id: string;
  span_id: string;
}

export interface TelemetryLogRecord {
  /** Winston level: error, warn, info, debug. */
  level: string;
  message: string;
  attributes?: SpanAttributes;
}

/**
 * Ships log records to the same collector as spans, so logs and errors show up
 * in the dashboard next to the traces they belong to. Implemented by the same
 * adapter as {@link TracingPort} — one endpoint, one lifecycle, one off switch.
 */
export interface LogShippingPort {
  emitLog(record: TelemetryLogRecord): void;
}

export interface TracingPort {
  /**
   * Runs `fn` inside a span that is current for its whole duration, so spans
   * started deeper (a Modbus write under a command) nest without plumbing.
   */
  inSpan<T>(name: string, options: SpanOptions, fn: (span: ActiveSpan) => Promise<T>): Promise<T>;

  /**
   * `traceparent` for the currently active span, to stamp on outbound
   * messages. Undefined when nothing is being traced.
   */
  currentTraceparent(): string | undefined;

  /** Trace/span ids for log correlation. Undefined when not tracing. */
  currentCorrelation(): TraceCorrelation | undefined;

  /** Flushes pending spans on shutdown. */
  shutdown(): Promise<void>;
}

const noopSpan: ActiveSpan = {
  setAttributes() {},
  recordFailure() {},
};

export const noopTracing: TracingPort & LogShippingPort = {
  async inSpan(_name, _options, fn) {
    return fn(noopSpan);
  },
  currentTraceparent() {
    return undefined;
  },
  currentCorrelation() {
    return undefined;
  },
  emitLog() {},
  async shutdown() {},
};
