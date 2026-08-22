import assert from 'node:assert/strict';
import { test } from 'node:test';
import { InMemorySpanExporter, SimpleSpanProcessor } from '@opentelemetry/sdk-trace-base';
import { OtelTracingAdapter } from '../../src/adapters/tracing/otel-tracing.adapter';
import { createTracing } from '../../src/adapters/tracing/create-tracing';
import { noopTracing } from '../../src/ports/tracing.port';

const TRACEPARENT_PATTERN = /^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/;

const LOCKER_UUID = '11111111-1111-1111-1111-111111111111';

/**
 * Exercises the real SDK wiring, which the fake TracingPort cannot cover, while
 * keeping spans in memory so no test ever touches the network.
 */
function createAdapter() {
  const exporter = new InMemorySpanExporter();

  const tracing = new OtelTracingAdapter({
    serviceName: 'open-locker-client',
    serviceInstanceId: LOCKER_UUID,
    endpoint: 'http://unused.invalid:4318',
    spanProcessors: [new SimpleSpanProcessor(exporter)],
  });

  return { tracing, exporter };
}

const traceIdOf = (traceparent: string): string => traceparent.split('-')[1] ?? '';

test('tracing stays off when no collector endpoint is configured', async () => {
  const tracing = await createTracing({ lockerUuid: LOCKER_UUID, env: {} });

  assert.equal(tracing, noopTracing);
  assert.equal(tracing.currentTraceparent(), undefined);
});

test('tracing stays off when the SDK is explicitly disabled', async () => {
  const tracing = await createTracing({
    lockerUuid: LOCKER_UUID,
    env: {
      OTEL_EXPORTER_OTLP_ENDPOINT: 'http://unused.invalid:4318',
      OTEL_SDK_DISABLED: 'true',
    },
  });

  assert.equal(tracing, noopTracing);
});

test('an endpoint switches tracing on', async () => {
  const tracing = await createTracing({
    lockerUuid: LOCKER_UUID,
    env: { OTEL_EXPORTER_OTLP_ENDPOINT: 'http://unused.invalid:4318' },
  });

  assert.notEqual(tracing, noopTracing);

  // Disposed immediately: this is the one adapter here with a real OTLP
  // pipeline, and it must never get the chance to reach for the network.
  await tracing.shutdown();
});

test('the adapter produces well-formed trace context and nests child spans', async () => {
  const { tracing, exporter } = createAdapter();

  // Nothing is active outside a span.
  assert.equal(tracing.currentTraceparent(), undefined);

  const { traceparent, correlation, nested } = await tracing.inSpan(
    'mqtt publish locker/test/command',
    { kind: 'producer', attributes: { 'open_locker.compartment_number': 3 } },
    async () => {
      // A nested span inherits the trace without being passed anything, which
      // is what makes a Modbus write land under the command that caused it.
      const nestedTraceparent = await tracing.inSpan('modbus flash_relay', {}, async () =>
        tracing.currentTraceparent(),
      );

      return {
        traceparent: tracing.currentTraceparent(),
        correlation: tracing.currentCorrelation(),
        nested: nestedTraceparent,
      };
    },
  );

  assert.match(traceparent ?? '', TRACEPARENT_PATTERN);
  assert.ok(correlation, 'log correlation should be available inside a span');
  assert.ok(traceparent?.includes(correlation.trace_id));

  assert.equal(traceIdOf(nested ?? ''), traceIdOf(traceparent ?? ''), 'same trace');
  assert.notEqual(nested, traceparent, 'but a different span');

  const exported = exporter.getFinishedSpans();
  const publish = exported.find((span) => span.name === 'mqtt publish locker/test/command');

  assert.ok(publish, 'the span should reach the exporter');
  assert.equal(publish.attributes['open_locker.compartment_number'], 3);
  assert.equal(publish.resource.attributes['service.name'], 'open-locker-client');
  assert.equal(publish.resource.attributes['service.instance.id'], LOCKER_UUID);
});

test('an inbound traceparent is continued, not replaced', async () => {
  const { tracing } = createAdapter();
  const remoteTraceId = '4bf92f3577b34da6a3ce929d0e0e4736';

  const traceparent = await tracing.inSpan(
    'mqtt process locker/test/command',
    { kind: 'consumer', parentTraceparent: `00-${remoteTraceId}-00f067aa0ba902b7-01` },
    async () => tracing.currentTraceparent(),
  );

  assert.equal(
    traceIdOf(traceparent ?? ''),
    remoteTraceId,
    'the backend trace must carry into the client',
  );
});

test('a malformed inbound traceparent starts a new trace instead of throwing', async () => {
  const { tracing } = createAdapter();

  const traceparent = await tracing.inSpan(
    'mqtt process locker/test/command',
    { kind: 'consumer', parentTraceparent: 'not-a-traceparent' },
    async () => tracing.currentTraceparent(),
  );

  assert.match(traceparent ?? '', TRACEPARENT_PATTERN);
});

test('a failing operation is recorded on the span but still throws', async () => {
  const { tracing, exporter } = createAdapter();

  await assert.rejects(
    tracing.inSpan('modbus read_discrete_inputs', {}, async () => {
      throw new Error('Timed out');
    }),
    /Timed out/,
  );

  const span = exporter.getFinishedSpans().find((s) => s.name === 'modbus read_discrete_inputs');

  assert.ok(span);
  assert.equal(span.status.code, 2, 'expected the span to be marked as an error');
});

test('repeated exporter failures are reported once per window, not per retry', async () => {
  const { diag } = await import('@opentelemetry/api');
  const warned: Array<{ message: string; meta?: Record<string, unknown> }> = [];
  const log = {
    warn: (message: string, meta?: Record<string, unknown>) => warned.push({ message, meta }),
    error: () => undefined,
  };

  const adapter = new OtelTracingAdapter({
    serviceName: 'test',
    serviceInstanceId: 'test-instance',
    endpoint: 'http://localhost:4318',
    log,
  });

  // What an unreachable collector produces: the same complaint, forever.
  for (let i = 0; i < 25; i++) {
    diag.error('Export failure: connect ECONNREFUSED 127.0.0.1:4318');
  }

  // Other adapters built earlier in this process also register globals, and the
  // SDK complains about that — count only the failure this test provoked.
  const refused = () =>
    warned.filter((entry) => String(entry.meta?.message).includes('ECONNREFUSED'));

  assert.equal(refused().length, 1, 'a broken exporter is reported, but only once');

  // A different failure is still its own signal.
  diag.error('Export failure: 413 payload too large');
  assert.equal(
    warned.filter((entry) => String(entry.meta?.message).includes('413')).length,
    1,
    'a distinct failure is not swallowed by the throttle',
  );

  await adapter.shutdown();
});

test('a diagnostic carrying span ids still throttles despite the ids differing', async () => {
  const { diag } = await import('@opentelemetry/api');
  const warned: Array<{ meta?: Record<string, unknown> }> = [];

  const adapter = new OtelTracingAdapter({
    serviceName: 'test',
    serviceInstanceId: 'test-instance',
    endpoint: 'http://localhost:4318',
    log: {
      warn: (_m: string, meta?: Record<string, unknown>) => warned.push({ meta }),
      error: () => undefined,
    },
  });

  // The SDK embeds trace and span ids in some warnings, so every occurrence is a
  // different string. Without normalisation each one is a new key: never
  // throttled, and a permanent map entry on a Pi that runs for months.
  for (let i = 0; i < 20; i++) {
    diag.warn(
      `Cannot execute the operation on ended Span {traceId: ${i.toString(16).padStart(32, '0')}}`,
    );
  }

  const ended = warned.filter((entry) => String(entry.meta?.message).includes('ended Span'));
  assert.equal(ended.length, 1, 'ids are normalised out of the throttle key');

  await adapter.shutdown();
});
