import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createOpenCompartmentHandler } from '../../src/adapters/mqtt/handlers/open-compartment.handler';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
import { OpenCompartmentUseCase } from '../../src/application/open-compartment';
import { PollCompartmentStateUseCase } from '../../src/application/state-publishing';
import { RunAfterCompleteScheduler } from '../../src/infrastructure/scheduler';
import { createTestConfigRepository } from '../helpers/test-config-repository';

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
    createTestConfigRepository(),
    new RunAfterCompleteScheduler(),
  );
  const pollSnapshot = new PollCompartmentStateUseCase(
    bus,
    createTestConfigRepository(),
    outbound,
    'locker/test/state/compartments',
  );
  const handler = createOpenCompartmentHandler({
    openCompartment,
    outbound,
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
    .map((payload) => JSON.parse(payload) as { result?: string })
    .find((message) => message.result === 'success');
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
