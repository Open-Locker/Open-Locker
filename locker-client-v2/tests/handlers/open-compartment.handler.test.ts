import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createOpenCompartmentHandler } from '../../src/adapters/mqtt/handlers/open-compartment.handler';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
import { InMemoryDedupStore } from '../../src/adapters/mqtt/dedup-store';
import { OpenCompartmentUseCase } from '../../src/application/open-compartment';
import { PollCompartmentStateUseCase } from '../../src/application/state-publishing';
import { RunAfterCompleteScheduler } from '../../src/infrastructure/scheduler';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';

const configStub: ConfigRepositoryPort = {
  load: () => ({
    modbus: { port: '/dev/null', flashDurationMs: 200 },
    compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
  }),
  reload: () => ({
    modbus: { port: '/dev/null', flashDurationMs: 200 },
    compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
  }),
  getCompartmentConfig: (n) => (n === 1 ? { compartment_number: 1, slaveId: 1, address: 0 } : null),
  hasExplicitRuntimeCompartments: () => true,
  getFlashDurationMs: () => 200,
  getHeartbeatIntervalSeconds: () => 15,
  getMqttTransportSettings: () => ({
    clean: false,
    keepalive: 60,
    reconnectPeriod: 5000,
    connectTimeout: 30000,
    maxReconnectAttempts: 0,
  }),
};

test('open compartment handler responds success and preserves transaction_id', async () => {
  const bus = new FakeLockerBus([1]);
  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      published.push(payload);
    },
    'locker/test/response',
    () => '2026-06-16T12:00:00.000Z',
  );

  const openCompartment = new OpenCompartmentUseCase(
    bus,
    configStub,
    new RunAfterCompleteScheduler(),
  );
  const pollSnapshot = new PollCompartmentStateUseCase(
    bus,
    configStub,
    outbound,
    'locker/test/state/compartments',
  );
  const dedup = new InMemoryDedupStore();
  const handler = createOpenCompartmentHandler({
    openCompartment,
    outbound,
    dedup,
    pollSnapshot,
  });

  await handler.handle(
    { lockerUuid: 'test' },
    {
      action: 'open_compartment',
      message_id: 'msg-1',
      transaction_id: 'tx-abc',
      timestamp: '2026-06-16T12:00:00.000Z',
      data: { compartment_number: 1 },
    },
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 1);
  const responsePayload = published
    .map((payload) => JSON.parse(payload) as { type?: string })
    .find((message) => message.type === 'command_response');
  assert.ok(responsePayload);
  const response = responsePayload as {
    result: string;
    transaction_id: string;
    message_id: string;
    timestamp: string;
  };
  assert.equal(response.result, 'success');
  assert.equal(response.transaction_id, 'tx-abc');
  assert.equal(typeof response.message_id, 'string');
  assert.equal(response.timestamp, '2026-06-16T12:00:00.000Z');
});

test('duplicate completed transaction triggers only one flash', async () => {
  const bus = new FakeLockerBus([1]);
  const outbound = new OutboundMqttAdapter(async () => undefined, 'locker/test/response');
  const dedup = new InMemoryDedupStore();
  dedup.markCommandCompleted('tx-dup', 'open_compartment');

  const handler = createOpenCompartmentHandler({
    openCompartment: new OpenCompartmentUseCase(bus, configStub, new RunAfterCompleteScheduler()),
    outbound,
    dedup,
    pollSnapshot: new PollCompartmentStateUseCase(
      bus,
      configStub,
      outbound,
      'locker/test/state/compartments',
    ),
  });

  await handler.handle(
    { lockerUuid: 'test' },
    {
      action: 'open_compartment',
      message_id: 'msg-2',
      transaction_id: 'tx-dup',
      timestamp: '2026-06-16T12:00:00.000Z',
      data: { compartment_number: 1 },
    },
  );

  assert.equal(bus.flashCalls.length, 0);
});
