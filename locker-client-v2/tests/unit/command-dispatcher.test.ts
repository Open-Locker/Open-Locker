import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CommandDispatcher } from '../../src/adapters/mqtt/command-dispatcher';
import { InboundProtocolGuard } from '../../src/adapters/mqtt/inbound-protocol-guard';
import { InMemoryDedupStore } from '../../src/adapters/mqtt/dedup-store';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
import { createOpenCompartmentHandler } from '../../src/adapters/mqtt/handlers/open-compartment.handler';
import { OpenCompartmentUseCase } from '../../src/application/open-compartment';
import { PollCompartmentStateUseCase } from '../../src/application/state-publishing';
import { RunAfterCompleteScheduler } from '../../src/infrastructure/scheduler';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
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

function createDispatcherHarness() {
  const bus = new FakeLockerBus([1]);
  const dedup = new InMemoryDedupStore();
  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      published.push(payload);
    },
    'locker/test/response',
    () => '2026-04-11T10:00:00Z',
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
  const dispatcher = new CommandDispatcher(new InboundProtocolGuard(dedup), outbound);
  dispatcher.register(
    createOpenCompartmentHandler({
      openCompartment,
      outbound,
      dedup,
      pollSnapshot,
    }),
  );

  return {
    bus,
    dedup,
    dispatcher,
    openCompartment,
    published,
  };
}

test('dispatcher executes valid open_compartment once', async () => {
  const { bus, dispatcher, openCompartment, published } = createDispatcherHarness();

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-1',
      message_id: 'msg-1',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 1 },
    }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 1);
  const response = published
    .map((payload) => JSON.parse(payload) as { type?: string; result?: string })
    .find((message) => message.type === 'command_response');
  assert.equal(response?.result, 'success');
});

test('dispatcher ignores duplicate message_id before side effects', async () => {
  const { bus, dispatcher, openCompartment, published } = createDispatcherHarness();

  const command = {
    action: 'open_compartment',
    transaction_id: 'txn-2',
    message_id: 'msg-dup',
    timestamp: '2026-04-11T10:00:00Z',
    data: { compartment_number: 1 },
  };

  await dispatcher.dispatch('locker/test/command', JSON.stringify(command));
  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({ ...command, data: { compartment_number: 7 } }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 1);
  assert.equal(
    published.filter(
      (payload) => (JSON.parse(payload) as { type?: string }).type === 'command_response',
    ).length,
    1,
  );
});

test('dispatcher rejects invalid payload with structured error', async () => {
  const { bus, dispatcher, openCompartment, published } = createDispatcherHarness();

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-invalid',
      message_id: 'msg-invalid',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 0 },
    }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 0);
  assert.equal(published.length, 1);
  const response = JSON.parse(published[0]!) as {
    result: string;
    error_code: string;
  };
  assert.equal(response.result, 'error');
  assert.equal(response.error_code, 'INVALID_COMMAND');
});

test('dispatcher rejects missing transaction_id without side effects', async () => {
  const { bus, dispatcher, openCompartment, published } = createDispatcherHarness();

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: '   ',
      message_id: 'msg-5',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 5 },
    }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 0);
  assert.equal(published.length, 0);
});
