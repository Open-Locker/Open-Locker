import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createOpenCompartmentHandler } from '../../src/adapters/mqtt/handlers/open-compartment.handler';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
import { OpenCompartmentUseCase } from '../../src/application/open-compartment';
import { RelayFireLog } from '../../src/domain/door-detection';
import { FakeDoorEventPublisher } from '../helpers/fake-door-event-publisher';
import { PollCompartmentStateUseCase } from '../../src/application/state-publishing';
import { RunAfterCompleteScheduler } from '../../src/infrastructure/scheduler';
import { createTestConfigRepository } from '../helpers/test-config-repository';

test('open compartment handler returns success and preserves transaction_id', async () => {
  const bus = new FakeLockerBus([1]);
  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      published.push(payload);
    },
    'locker/test/response',
    () => '2026-06-16T12:00:00.000Z',
  );

  const config = createTestConfigRepository({
    compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
  });
  const openCompartment = new OpenCompartmentUseCase({
    bus,
    config,
    scheduler: new RunAfterCompleteScheduler(),
    doorEvents: new FakeDoorEventPublisher(),
    relayFireLog: new RelayFireLog(),
  });
  const pollSnapshot = new PollCompartmentStateUseCase(
    bus,
    config,
    outbound,
    'locker/test/state/compartments',
  );
  const handler = createOpenCompartmentHandler({
    openCompartment,
    pollSnapshot,
  });

  const response = await handler.handle(
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
  assert.equal(response.result, 'success');
  assert.equal(response.transaction_id, 'tx-abc');
  assert.equal(response.message, 'Unlock pulse sent.');
  assert.equal(
    published
      .map((payload) => JSON.parse(payload) as { compartments?: unknown[] })
      .filter((payload) => payload.compartments).length,
    1,
  );
});
