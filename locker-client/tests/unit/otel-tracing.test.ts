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
