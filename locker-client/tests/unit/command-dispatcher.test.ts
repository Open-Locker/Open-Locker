import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CommandDispatcher } from '../../src/adapters/mqtt/command-dispatcher';
import { InboundProtocolGuard } from '../../src/adapters/mqtt/inbound-protocol-guard';
import { InMemoryDedupStore } from '../../src/adapters/mqtt/dedup-store';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
import { recordCommandResponses } from '../../src/adapters/mqtt/recording-outbound';
import { createOpenCompartmentHandler } from '../../src/adapters/mqtt/handlers/open-compartment.handler';
import { createApplyConfigHandler } from '../../src/adapters/mqtt/handlers/apply-config.handler';
import { OpenCompartmentUseCase } from '../../src/application/open-compartment';
import { RelayFireLog } from '../../src/domain/door-detection';
import { FakeDoorEventPublisher } from '../helpers/fake-door-event-publisher';
import { ApplyConfigUseCase } from '../../src/application/apply-config';
import { PollCompartmentStateUseCase } from '../../src/application/state-publishing';
import { RunAfterCompleteScheduler } from '../../src/infrastructure/scheduler';
import { computeAppliedConfigHash } from '../../src/domain/config-normalization';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { MemoryOverlayStore } from '../helpers/memory-overlay-store';
import { createTestConfigRepository } from '../helpers/test-config-repository';
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
  getConfiguredSlaveIds: () => [1],
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

function createDispatcherHarness(bus = new FakeLockerBus([1])) {
  const dedup = new InMemoryDedupStore();
  const published: string[] = [];
  const outbound = recordCommandResponses(
    new OutboundMqttAdapter(
      async (_topic, payload) => {
        published.push(payload);
        return true;
      },
      'locker/test/response',
      () => '2026-04-11T10:00:00Z',
    ),
    dedup,
  );
  const openCompartment = new OpenCompartmentUseCase({
    bus,
    config: configStub,
    scheduler: new RunAfterCompleteScheduler(),
    doorEvents: new FakeDoorEventPublisher(),
    relayFireLog: new RelayFireLog(),
  });
  const pollSnapshot = new PollCompartmentStateUseCase(
    bus,
    configStub,
    outbound,
    'locker/test/state/compartments',
  );
  const dispatcher = new CommandDispatcher(new InboundProtocolGuard(dedup), outbound, dedup);
  dispatcher.register(
    createOpenCompartmentHandler({
      openCompartment,
      outbound,
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

function commandResponses(published: string[]) {
  return published
    .map((payload) => JSON.parse(payload) as { result?: string; transaction_id?: string })
    .filter((message) => message.result === 'success' || message.result === 'error');
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
  assert.equal(commandResponses(published)[0]?.result, 'success');
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
  // The redelivery is answered by replaying the first response, so there are two
  // replies but only one execution.
  assert.equal(commandResponses(published).length, 2);
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

test('failed open marks completed and a retry is answered with the same failure', async () => {
  const bus = new FakeLockerBus([1]);
  let flashAttempts = 0;
  const originalFlash = bus.flashRelay.bind(bus);
  bus.flashRelay = async (target, durationMs) => {
    flashAttempts++;
    if (flashAttempts === 1) {
      throw new Error('modbus failed');
    }
    return originalFlash(target, durationMs);
  };

  const { dedup, dispatcher, openCompartment, published } = createDispatcherHarness(bus);

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-retry',
      message_id: 'msg-fail',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 1 },
    }),
  );

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-retry',
      message_id: 'msg-retry',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 1 },
    }),
  );

  openCompartment.stopAllMonitoring();

  const responses = commandResponses(published);
  // The retry is answered by replaying the original failure: the backend gets
  // the outcome it missed, and the relay is not fired a second time.
  assert.equal(responses.length, 2);
  assert.deepEqual(
    responses.map((response) => response.result),
    ['error', 'error'],
  );
  assert.equal(flashAttempts, 1);
  assert.equal(dedup.getCommandRecord('txn-retry')?.status, 'completed');
});

test('duplicate completed open_compartment is silently ignored', async () => {
  const { bus, dedup, dispatcher, openCompartment, published } = createDispatcherHarness();
  dedup.markCommandCompleted('txn-dup', 'open_compartment');

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-dup',
      message_id: 'msg-dup',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 1 },
    }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 0);
  assert.equal(commandResponses(published).length, 0);
});

test('apply_config deduplicates completed transaction without re-running', async () => {
  const bus = new FakeLockerBus([1]);
  const dedup = new InMemoryDedupStore();
  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(async (_topic, payload) => {
    published.push(payload);
    return true;
  }, 'locker/test/response');
  const compartments = [{ compartment_number: 1, slaveId: 1, address: 0 }];
  const configHash = computeAppliedConfigHash(compartments);
  const applyConfig = new ApplyConfigUseCase({
    overlayStore: new MemoryOverlayStore(),
    config: createTestConfigRepository({ compartments }),
    bus,
    restartHeartbeat: () => undefined,
    restartPolling: () => undefined,
  });
  const dispatcher = new CommandDispatcher(new InboundProtocolGuard(dedup), outbound, dedup);
  dispatcher.register(createApplyConfigHandler({ applyConfig, outbound }));

  dedup.markCommandCompleted('txn-apply-dup', 'apply_config');

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'apply_config',
      transaction_id: 'txn-apply-dup',
      message_id: 'msg-apply-dup',
      timestamp: '2026-04-11T10:00:00Z',
      data: {
        config_hash: configHash,
        heartbeat_interval_seconds: 30,
        compartments,
      },
    }),
  );

  assert.equal(commandResponses(published).length, 0);
});

test('dispatcher answers an unknown action so the backend stops waiting', async () => {
  const { dispatcher, published } = createDispatcherHarness();

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'do_something_unsupported',
      transaction_id: 'txn-unknown-action',
      message_id: 'msg-unknown-action',
      timestamp: '2026-04-11T10:00:00Z',
    }),
  );

  const responses = commandResponses(published);
  assert.equal(responses.length, 1);
  const response = JSON.parse(published[0]!) as {
    result: string;
    error_code: string;
    transaction_id: string;
    action: string;
  };
  assert.equal(response.result, 'error');
  assert.equal(response.error_code, 'UNKNOWN_ACTION');
  assert.equal(response.transaction_id, 'txn-unknown-action');
  assert.equal(response.action, 'do_something_unsupported');
});

test('dispatcher answers a command that carries no action', async () => {
  const { dispatcher, published } = createDispatcherHarness();

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      transaction_id: 'txn-no-action',
      message_id: 'msg-no-action',
      timestamp: '2026-04-11T10:00:00Z',
    }),
  );

  assert.equal(commandResponses(published).length, 1);
  const response = JSON.parse(published[0]!) as { error_code: string; transaction_id: string };
  assert.equal(response.error_code, 'INVALID_COMMAND');
  assert.equal(response.transaction_id, 'txn-no-action');
});

test('dispatcher answers a command missing message_id', async () => {
  const { dispatcher, published } = createDispatcherHarness();

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-no-message-id',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 1 },
    }),
  );

  assert.equal(commandResponses(published).length, 1);
  const response = JSON.parse(published[0]!) as { error_code: string; transaction_id: string };
  assert.equal(response.error_code, 'MISSING_MESSAGE_ID');
  assert.equal(response.transaction_id, 'txn-no-message-id');
});

test('a rejected command with no transaction_id stays unanswered', async () => {
  const { dispatcher, published } = createDispatcherHarness();

  // Nothing to correlate a reply against, so the rejection is a log line only.
  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'do_something_unsupported',
      message_id: 'msg-orphan',
      timestamp: '2026-04-11T10:00:00Z',
    }),
  );

  assert.equal(commandResponses(published).length, 0);
});

test('a duplicate message_id replays the original answer instead of an error', async () => {
  const { dispatcher, openCompartment, published } = createDispatcherHarness();

  const command = {
    action: 'open_compartment',
    transaction_id: 'txn-dup-reply',
    message_id: 'msg-dup-reply',
    timestamp: '2026-04-11T10:00:00Z',
    data: { compartment_number: 1 },
  };

  await dispatcher.dispatch('locker/test/command', JSON.stringify(command));
  await dispatcher.dispatch('locker/test/command', JSON.stringify(command));

  openCompartment.stopAllMonitoring();
  const responses = commandResponses(published);
  assert.equal(responses.length, 2, 'the redelivery should be answered too');
  assert.deepEqual(
    responses.map((response) => response.result),
    ['success', 'success'],
  );
  assert.deepEqual(
    responses.map((response) => response.transaction_id),
    ['txn-dup-reply', 'txn-dup-reply'],
  );
});

test('a replayed response carries a fresh message_id', async () => {
  const { dispatcher, openCompartment, published } = createDispatcherHarness();

  const command = {
    action: 'open_compartment',
    transaction_id: 'txn-fresh-id',
    message_id: 'msg-fresh-id',
    timestamp: '2026-04-11T10:00:00Z',
    data: { compartment_number: 1 },
  };

  await dispatcher.dispatch('locker/test/command', JSON.stringify(command));
  await dispatcher.dispatch('locker/test/command', JSON.stringify(command));

  openCompartment.stopAllMonitoring();
  const messageIds = published
    .map((payload) => JSON.parse(payload) as { message_id?: string; result?: string })
    .filter((message) => message.result !== undefined)
    .map((message) => message.message_id);

  assert.equal(messageIds.length, 2);
  // A byte-identical replay would be discarded by the backend's own message_id
  // dedup, so the envelope has to be freshly stamped.
  assert.notEqual(messageIds[0], messageIds[1]);
});

test('a duplicate transaction replays the completed response', async () => {
  const { dedup, dispatcher, openCompartment, published } = createDispatcherHarness();

  const command = {
    action: 'open_compartment',
    transaction_id: 'txn-replay-completed',
    message_id: 'msg-replay-1',
    timestamp: '2026-04-11T10:00:00Z',
    data: { compartment_number: 1 },
  };

  await dispatcher.dispatch('locker/test/command', JSON.stringify(command));
  assert.equal(dedup.getCommandRecord('txn-replay-completed')?.status, 'completed');

  // Same transaction, new message_id: passes the message dedup and lands on the
  // completed-transaction path instead.
  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({ ...command, message_id: 'msg-replay-2' }),
  );

  openCompartment.stopAllMonitoring();
  const responses = commandResponses(published);
  assert.equal(responses.length, 2);
  assert.equal(responses[1]?.result, 'success');
  assert.equal(responses[1]?.transaction_id, 'txn-replay-completed');
});

test('a duplicate with nothing stored to replay stays silent', async () => {
  const { dedup, dispatcher, openCompartment, published } = createDispatcherHarness();

  // A transaction marked completed without a retained response — what a restart
  // that lost state looks like.
  dedup.markCommandCompleted('txn-no-stored', 'open_compartment');

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-no-stored',
      message_id: 'msg-no-stored',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 1 },
    }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(commandResponses(published).length, 0);
});
