import assert from 'node:assert/strict';
import { test } from 'node:test';
import { z } from 'zod';
import { CommandDispatcher } from '../../src/adapters/mqtt/command-dispatcher';
import { InboundProtocolGuard } from '../../src/adapters/mqtt/inbound-protocol-guard';
import { InMemoryDedupStore } from '../../src/adapters/mqtt/dedup-store';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
import { WaveshareModbusBusActor } from '../../src/adapters/modbus/waveshare-modbus-bus-actor';
import { mqttSpanAttributes, spanDestination } from '../../src/domain/mqtt-span-attributes';
import { readTraceparent, TRACEPARENT_FIELD } from '../../src/domain/trace-context';
import * as attr from '../../src/domain/trace-attributes';
import { noopTracing } from '../../src/ports/tracing.port';
import { RecordingTracing } from '../helpers/recording-tracing';

const LOCKER_UUID = '11111111-1111-1111-1111-111111111111';
const TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

function createOutbound(tracing = new RecordingTracing()) {
  const published: { topic: string; payload: string }[] = [];
  const outbound = new OutboundMqttAdapter(
    async (topic, payload) => {
      published.push({ topic, payload });
    },
    `locker/${LOCKER_UUID}/response`,
    () => '2026-07-28T10:00:00Z',
    tracing,
  );

  return { outbound, published, tracing };
}

test('outbound publish stamps trace context on the envelope', async () => {
  const { outbound, published } = createOutbound();

  await outbound.publishCommandResponse({
    action: 'open_compartment',
    result: 'success',
    transaction_id: 'txn-1',
  });

  const payload = JSON.parse(published[0]!.payload) as Record<string, unknown>;

  assert.equal(payload[TRACEPARENT_FIELD], TRACEPARENT);
  // The rest of the envelope is untouched.
  assert.equal(payload.transaction_id, 'txn-1');
  assert.equal(typeof payload.message_id, 'string');
});

test('outbound publish emits a producer span carrying the domain attributes', async () => {
  const { outbound, tracing } = createOutbound();

  await outbound.publishJson(`locker/${LOCKER_UUID}/event`, {
    event: 'compartment_open_detected',
    data: { compartment_number: 7, transaction_id: 'txn-9' },
  });

  const span = tracing.find(`mqtt publish locker/${LOCKER_UUID}/event`);

  assert.ok(span, 'expected a span for the publish');
  assert.equal(span.kind, 'producer');
  assert.equal(span.attributes[attr.MESSAGING_SYSTEM], 'mqtt');
  assert.equal(span.attributes[attr.EVENT], 'compartment_open_detected');
  assert.equal(span.attributes[attr.COMPARTMENT_NUMBER], 7);
  // Events carry the transaction inside `data`.
  assert.equal(span.attributes[attr.TRANSACTION_ID], 'txn-9');
  assert.equal(span.attributes[attr.LOCKER_UUID], LOCKER_UUID);
});

test('publishing without tracing leaves the payload exactly as it was', async () => {
  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      published.push(payload);
    },
    `locker/${LOCKER_UUID}/response`,
    () => '2026-07-28T10:00:00Z',
    noopTracing,
  );

  await outbound.publishCommandResponse({
    action: 'open_compartment',
    result: 'success',
    transaction_id: 'txn-1',
  });

  const payload = JSON.parse(published[0]!) as Record<string, unknown>;

  assert.equal(TRACEPARENT_FIELD in payload, false);
});

test('heartbeats and snapshots are neither traced nor stamped', async () => {
  const { outbound, published, tracing } = createOutbound();

  await outbound.publishJson(`locker/${LOCKER_UUID}/state/heartbeat`, { uptime_seconds: 60 });
  await outbound.publishJson(`locker/${LOCKER_UUID}/state/compartments`, {
    compartments: [{ compartment_number: 1, door_state: 'closed' }],
  });

  assert.deepEqual(tracing.spans, [], 'periodic chatter must not produce spans');

  for (const { payload } of published) {
    const body = JSON.parse(payload) as Record<string, unknown>;
    assert.equal(
      TRACEPARENT_FIELD in body,
      false,
      'an untraced publish must not carry trace context',
    );
    // The envelope is otherwise untouched.
    assert.equal(typeof body.message_id, 'string');
    assert.equal(typeof body.timestamp, 'string');
  }
});

test('a provisioning token never reaches a span name or destination', () => {
  const token = 't0ken'.repeat(12);
  const topic = `locker/register/${token}`;

  const destination = spanDestination(topic);
  const attributes = mqttSpanAttributes(topic, { message_id: 'm-1' });

  assert.equal(destination, 'locker/register/{token}');
  assert.equal(
    JSON.stringify({ destination, attributes }).includes(token),
    false,
    'the token must not appear in any span field',
  );
  // "register" is not a locker UUID.
  assert.equal(attributes[attr.LOCKER_UUID], undefined);
});

test('inbound trace context is read when present and ignored when not', () => {
  assert.equal(readTraceparent({ [TRACEPARENT_FIELD]: TRACEPARENT }), TRACEPARENT);
  assert.equal(readTraceparent({}), undefined);
  assert.equal(readTraceparent({ [TRACEPARENT_FIELD]: '' }), undefined);
  assert.equal(readTraceparent({ [TRACEPARENT_FIELD]: 42 }), undefined);
});

function createTracedDispatcher() {
  const tracing = new RecordingTracing();
  const handled: string[] = [];
  const dedup = new InMemoryDedupStore();

  const dispatcher = new CommandDispatcher(
    new InboundProtocolGuard(dedup),
    new OutboundMqttAdapter(async () => {}, `locker/${LOCKER_UUID}/response`, undefined, tracing),
    dedup,
    tracing,
  );

  dispatcher.register({
    action: 'open_compartment',
    schema: z.object({ transaction_id: z.string() }).loose(),
    requiresTransactionId: () => true,
    async handle(_context, payload) {
      handled.push('open_compartment');
      return {
        action: 'open_compartment',
        result: 'success' as const,
        transaction_id: (payload as { transaction_id: string }).transaction_id,
      };
    },
  });

  return { dispatcher, tracing, handled };
}

function command(extra: Record<string, unknown> = {}): string {
  return JSON.stringify({
    message_id: `m-${Math.random()}`,
    transaction_id: `t-${Math.random()}`,
    action: 'open_compartment',
    timestamp: '2026-07-28T10:00:00Z',
    data: { compartment_number: 4 },
    ...extra,
  });
}

test('an inbound command continues the trace the backend started', async () => {
  const { dispatcher, tracing, handled } = createTracedDispatcher();

  await dispatcher.dispatch(
    `locker/${LOCKER_UUID}/command`,
    command({ [TRACEPARENT_FIELD]: TRACEPARENT }),
  );

  const span = tracing.find(`mqtt process locker/${LOCKER_UUID}/command`);

  assert.ok(span, 'expected a consumer span for the command');
  assert.equal(span.kind, 'consumer');
  assert.equal(span.parentTraceparent, TRACEPARENT);
  assert.equal(span.attributes[attr.ACTION], 'open_compartment');
  assert.equal(span.attributes[attr.COMPARTMENT_NUMBER], 4);
  assert.deepEqual(handled, ['open_compartment']);
});

test('a command without trace context is still handled, as a new trace', async () => {
  const { dispatcher, tracing, handled } = createTracedDispatcher();

  await dispatcher.dispatch(`locker/${LOCKER_UUID}/command`, command());

  const span = tracing.find(`mqtt process locker/${LOCKER_UUID}/command`);

  assert.ok(span);
  assert.equal(span.parentTraceparent, undefined);
  assert.deepEqual(handled, ['open_compartment'], 'the command must still run');
});

test('unparseable messages are dropped without opening a span', async () => {
  const { dispatcher, tracing } = createTracedDispatcher();

  await dispatcher.dispatch(`locker/${LOCKER_UUID}/command`, 'not json');

  assert.deepEqual(tracing.spans, []);
});

test('modbus operations are traced with the board they addressed', async () => {
  const tracing = new RecordingTracing();
  const calls: string[] = [];

  const bus = new WaveshareModbusBusActor(
    {
      async connect() {},
      async disconnect() {},
      isOpen: () => true,
      async flashRelayOn() {
        calls.push('flash');
      },
      async readCoils() {
        return [true];
      },
      async readDiscreteInputs() {
        return [false];
      },
      async turnAllRelaysOff() {},
    },
    { maxAttempts: 1, delayMs: 0 },
    [3],
    tracing,
  );

  await bus.flashRelay({ compartmentNumber: 2, slaveId: 3, relayAddress: 5 }, 400);
  await bus.readDoorSensors(3, 0, 1);

  const flash = tracing.find('modbus flash_relay');
  assert.ok(flash, 'expected a span for the relay pulse');
  assert.equal(flash.kind, 'internal');
  assert.equal(flash.attributes[attr.MODBUS_SLAVE_ID], 3);
  assert.equal(flash.attributes[attr.MODBUS_ADDRESS], 5);
  assert.equal(flash.attributes[attr.MODBUS_DURATION_MS], 400);
  assert.equal(flash.attributes[attr.COMPARTMENT_NUMBER], 2);
  assert.equal(calls.length, 1);

  const read = tracing.find('modbus read_discrete_inputs');
  assert.ok(read, 'expected a span for the door read');
  assert.equal(read.attributes[attr.MODBUS_LENGTH], 1);
});

test('an unreachable board still records the failure on its span', async () => {
  const tracing = new RecordingTracing();

  const bus = new WaveshareModbusBusActor(
    {
      async connect() {},
      async disconnect() {},
      isOpen: () => true,
      async flashRelayOn() {},
      async readCoils() {
        return [];
      },
      async readDiscreteInputs(): Promise<boolean[]> {
        throw new Error('Timed out');
      },
      async turnAllRelaysOff() {},
    },
    { maxAttempts: 1, delayMs: 0 },
    [1],
    tracing,
  );

  // Door reads degrade to "unknown" rather than throwing.
  assert.deepEqual(await bus.readDoorSensors(1, 0, 2), ['unknown', 'unknown']);

  const span = tracing.find('modbus read_discrete_inputs');

  assert.ok(span);
  assert.equal(span.failed, true, 'the timeout must be visible on the trace');
});
