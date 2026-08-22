import type {
  ActiveSpan,
  SpanAttributes,
  SpanOptions,
  TraceCorrelation,
  TracingPort,
} from '../../src/ports/tracing.port';

export interface RecordedSpan {
  name: string;
  kind: string;
  attributes: SpanAttributes;
  parentTraceparent?: string;
  failed: boolean;
}

/**
 * A TracingPort that records what was traced, so tests can assert on spans
 * without loading the OpenTelemetry SDK.
 *
 * `traceparent` is a fixed, well-formed value: the ids themselves are the SDK's
 * business, and what these tests care about is whether it reaches the wire.
 */
export class RecordingTracing implements TracingPort {
  readonly spans: RecordedSpan[] = [];

  constructor(
    private readonly traceparent: string | undefined = '00-'.concat(
      '4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
    ),
  ) {}

  async inSpan<T>(
    name: string,
    options: SpanOptions,
    fn: (span: ActiveSpan) => Promise<T>,
  ): Promise<T> {
    const recorded: RecordedSpan = {
      name,
      kind: options.kind ?? 'internal',
      attributes: { ...options.attributes },
      parentTraceparent: options.parentTraceparent,
      failed: false,
    };

    this.spans.push(recorded);

    const span: ActiveSpan = {
      setAttributes(attributes) {
        Object.assign(recorded.attributes, attributes);
      },
      recordFailure() {
        recorded.failed = true;
      },
    };

    try {
      return await fn(span);
    } catch (error) {
      recorded.failed = true;
      throw error;
    }
  }

  currentTraceparent(): string | undefined {
    return this.traceparent;
  }

  currentCorrelation(): TraceCorrelation | undefined {
    return this.traceparent
      ? { trace_id: '4bf92f3577b34da6a3ce929d0e0e4736', span_id: '00f067aa0ba902b7' }
      : undefined;
  }

  async shutdown(): Promise<void> {}

  find(name: string): RecordedSpan | undefined {
    return this.spans.find((span) => span.name === name);
  }
}
