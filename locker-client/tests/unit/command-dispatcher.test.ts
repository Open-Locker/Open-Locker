import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { CommandDispatcher } from '../../src/adapters/mqtt/command-dispatcher';
import { InboundProtocolGuard } from '../../src/adapters/mqtt/inbound-protocol-guard';
import { FileDedupStore, InMemoryDedupStore } from '../../src/adapters/mqtt/dedup-store';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
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
import { FakeMqttTransport } from '../helpers/fake-mqtt-transport';
import { MemoryOverlayStore } from '../helpers/memory-overlay-store';
import { createTestConfigRepository } from '../helpers/test-config-repository';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';
import type { DedupStorePort } from '../../src/ports/mqtt.port';

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

function createDispatcherHarness(
  bus = new FakeLockerBus([1]),
  dedup: DedupStorePort = new InMemoryDedupStore(),
) {
  const published: string[] = [];
  const attempted: string[] = [];
  let connected = true;
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      attempted.push(payload);
      if (!connected) {
        throw new Error('MQTT client is not connected');
      }
      published.push(payload);
    },
    'locker/test/response',
    () => '2026-04-11T10:00:00Z',
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
      pollSnapshot,
    }),
  );

  return {
    bus,
    dedup,
    dispatcher,
    outbound,
    openCompartment,
    published,
    attempted,
    setConnected(value: boolean) {
      connected = value;
    },
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

test('dispatcher serializes hardware and configuration commands', async () => {
  const bus = new FakeLockerBus([1]);
  let flashCount = 0;
  let notifyFirstFlash!: () => void;
  const firstFlashStarted = new Promise<void>((resolve) => {
    notifyFirstFlash = resolve;
  });
  let releaseFirstFlash!: () => void;
  const firstFlashGate = new Promise<void>((resolve) => {
    releaseFirstFlash = resolve;
  });
  bus.flashRelay = async () => {
    flashCount++;
    if (flashCount === 1) {
      notifyFirstFlash();
      await firstFlashGate;
    }
    return 'pulse_sent';
  };
  const { dispatcher, openCompartment } = createDispatcherHarness(bus);
  const command = (suffix: string) =>
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: `txn-serial-${suffix}`,
      message_id: `msg-serial-${suffix}`,
      timestamp: '2026-08-23T12:00:00Z',
      data: { compartment_number: 1 },
    });

  const first = dispatcher.dispatch('locker/test/command', command('first'));
  await firstFlashStarted;
  const second = dispatcher.dispatch('locker/test/command', command('second'));
  await Promise.resolve();
  assert.equal(flashCount, 1);

  releaseFirstFlash();
  await Promise.all([first, second]);
  openCompartment.stopAllMonitoring();
  assert.equal(flashCount, 2);
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

test('legacy dedup migration never repeats a completed physical command', async (t) => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-legacy-dedup-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  const file = path.join(directory, 'dedup.json');
  fs.writeFileSync(
    file,
    JSON.stringify({
      seenMessageIds: {},
      commandRecords: {
        'txn-legacy-completed': {
          action: 'open_compartment',
          status: 'completed',
          updatedAt: '2026-04-11T10:00:00Z',
        },
      },
    }),
    'utf8',
  );
  const store = new FileDedupStore(file);
  store.assertHealthy();
  const { bus, dispatcher, openCompartment, published } = createDispatcherHarness(
    new FakeLockerBus([1]),
    store,
  );

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-legacy-completed',
      message_id: 'msg-after-migration',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 1 },
    }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 0);
  assert.equal(commandResponses(published).length, 0);
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

test('invalid response remains persistently pending after publish failure and can be flushed', async (t) => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-invalid-response-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  const file = path.join(directory, 'dedup.json');
  const { bus, dispatcher, openCompartment, outbound, published, setConnected } =
    createDispatcherHarness(new FakeLockerBus([1]), new FileDedupStore(file));
  setConnected(false);

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-invalid-pending',
      message_id: 'msg-invalid-pending',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 0 },
    }),
  );

  const restartedStore = new FileDedupStore(file);
  const pendingRecord = restartedStore.getCommandRecord('txn-invalid-pending');
  assert.equal(bus.flashCalls.length, 0);
  assert.equal(pendingRecord?.status, 'completed');
  assert.equal(pendingRecord?.response?.error_code, 'INVALID_COMMAND');
  assert.equal(pendingRecord?.responseDeliveredAt, undefined);

  setConnected(true);
  const restartedDispatcher = new CommandDispatcher(
    new InboundProtocolGuard(restartedStore),
    outbound,
    restartedStore,
  );
  await restartedDispatcher.flushPendingResponses();

  openCompartment.stopAllMonitoring();
  assert.equal(commandResponses(published).length, 1);
  assert.ok(restartedStore.getCommandRecord('txn-invalid-pending')?.responseDeliveredAt);
});

test('invalid duplicate does not overwrite a completed success response', async () => {
  const { bus, dedup, dispatcher, openCompartment, published } = createDispatcherHarness();
  const successResponse = {
    result: 'success' as const,
    message: 'Compartment opened.',
  };
  dedup.markCommandCompleted('txn-invalid-duplicate', 'open_compartment', successResponse);

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-invalid-duplicate',
      message_id: 'msg-invalid-duplicate',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 0 },
    }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 0);
  assert.deepEqual(dedup.getCommandRecord('txn-invalid-duplicate')?.response, successResponse);
  assert.equal(commandResponses(published)[0]?.result, 'success');
});

test('invalid duplicate leaves an in_progress command untouched', async () => {
  const { bus, dedup, dispatcher, openCompartment, published } = createDispatcherHarness();
  dedup.markCommandInProgress('txn-invalid-in-progress', 'open_compartment');
  const existing = dedup.getCommandRecord('txn-invalid-in-progress');

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-invalid-in-progress',
      message_id: 'msg-invalid-in-progress',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 0 },
    }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 0);
  assert.deepEqual(dedup.getCommandRecord('txn-invalid-in-progress'), existing);
  assert.equal(commandResponses(published).length, 0);
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

test('duplicate completed open_compartment replays its stored response', async () => {
  const { bus, dedup, dispatcher, openCompartment, published } = createDispatcherHarness();
  dedup.markCommandCompleted('txn-dup', 'open_compartment', {
    result: 'success',
    message: 'Compartment opened.',
  });

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
  assert.equal(commandResponses(published).length, 1);
  assert.equal(commandResponses(published)[0]?.transaction_id, 'txn-dup');
});

test('apply_config replays a completed response without re-running', async () => {
  const bus = new FakeLockerBus([1]);
  const dedup = new InMemoryDedupStore();
  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(async (_topic, payload) => {
    published.push(payload);
  }, 'locker/test/response');
  const compartments = [{ compartment_number: 1, slaveId: 1, address: 0 }];
  const configHash = computeAppliedConfigHash(compartments);
  const overlayStore = new MemoryOverlayStore();
  const applyConfig = new ApplyConfigUseCase({
    overlayStore,
    config: createTestConfigRepository({ compartments }),
    bus,
    restartHeartbeat: () => undefined,
    restartPolling: () => undefined,
  });
  const dispatcher = new CommandDispatcher(new InboundProtocolGuard(dedup), outbound, dedup);
  dispatcher.register(createApplyConfigHandler({ applyConfig }));

  dedup.markCommandCompleted('txn-apply-dup', 'apply_config', {
    result: 'success',
    applied_config_hash: configHash,
    message: 'Configuration applied.',
  });

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'apply_config',
      transaction_id: 'txn-apply-dup',
      message_id: 'msg-apply-dup',
      timestamp: '2026-04-11T10:00:00Z',
      data: {
        adapter_type: 'waveshare_modbus',
        channel_count: 8,
        feedback_type: 'door_closing',
        config_hash: configHash,
        heartbeat_interval_seconds: 30,
        compartments,
      },
    }),
  );

  assert.equal(commandResponses(published).length, 1);
  assert.equal(overlayStore.load(), null);
});

test('open response publish failure keeps a replayable final response', async () => {
  const { bus, dedup, dispatcher, openCompartment, published, setConnected } =
    createDispatcherHarness();
  setConnected(false);

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-pending-open',
      message_id: 'msg-pending-open',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 1 },
    }),
  );

  const pendingRecord = dedup.getCommandRecord('txn-pending-open');
  assert.equal(bus.flashCalls.length, 1);
  assert.equal(pendingRecord?.status, 'completed');
  assert.equal(pendingRecord?.response?.result, 'success');
  assert.equal(pendingRecord?.responseDeliveredAt, undefined);

  setConnected(true);
  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-pending-open',
      message_id: 'msg-pending-open-retry',
      timestamp: '2026-04-11T10:00:01Z',
      data: { compartment_number: 1 },
    }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 1);
  assert.equal(commandResponses(published).length, 1);
  assert.ok(dedup.getCommandRecord('txn-pending-open')?.responseDeliveredAt);
});

test('startup recovery finalizes in_progress without executing hardware', async () => {
  const { bus, dedup, dispatcher, openCompartment, published } = createDispatcherHarness();
  dedup.markCommandInProgress('txn-interrupted', 'open_compartment');

  dispatcher.recoverInterruptedCommands();
  const recovered = dedup.getCommandRecord('txn-interrupted');

  assert.equal(bus.flashCalls.length, 0);
  assert.equal(recovered?.status, 'completed');
  assert.equal(recovered?.response?.result, 'error');
  assert.equal(recovered?.response?.error_code, 'UNKNOWN_ERROR');
  assert.equal(recovered?.responseDeliveredAt, undefined);

  await dispatcher.flushPendingResponses();
  openCompartment.stopAllMonitoring();
  assert.equal(commandResponses(published).length, 1);
  assert.ok(dedup.getCommandRecord('txn-interrupted')?.responseDeliveredAt);
});

test('reconnect flushes pending responses without repeating hardware', async () => {
  const { bus, dedup, dispatcher, openCompartment, published, setConnected } =
    createDispatcherHarness();
  const transport = new FakeMqttTransport();
  await transport.connect();
  transport.onConnected(async () => {
    setConnected(true);
    await dispatcher.flushPendingResponses();
  });

  setConnected(false);
  transport.simulateBrokerDrop();
  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-reconnect',
      message_id: 'msg-reconnect',
      timestamp: '2026-04-11T10:00:00Z',
      data: { compartment_number: 1 },
    }),
  );

  await transport.simulateBrokerRestore();
  transport.simulateBrokerDrop();
  await transport.simulateBrokerRestore();
  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-reconnect',
      message_id: 'msg-reconnect-duplicate',
      timestamp: '2026-04-11T10:00:01Z',
      data: { compartment_number: 1 },
    }),
  );

  openCompartment.stopAllMonitoring();
  assert.equal(bus.flashCalls.length, 1);
  assert.equal(commandResponses(published).length, 2);
  assert.ok(dedup.getCommandRecord('txn-reconnect')?.responseDeliveredAt);
});

test('failed delivered duplicate replay is pending and flushes on reconnect', async (t) => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-delivered-replay-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  const file = path.join(directory, 'dedup.json');
  const { bus, dedup, dispatcher, openCompartment, published, attempted, setConnected } =
    createDispatcherHarness(new FakeLockerBus([1]), new FileDedupStore(file));
  t.after(() => openCompartment.stopAllMonitoring());
  const transport = new FakeMqttTransport();
  await transport.connect();
  transport.onConnected(() => dispatcher.flushPendingResponses());
  const command = {
    action: 'open_compartment',
    transaction_id: 'txn-delivered-replay',
    message_id: 'msg-delivered-original',
    timestamp: '2026-04-11T10:00:00Z',
    data: { compartment_number: 1 },
  };

  await dispatcher.dispatch('locker/test/command', JSON.stringify(command));
  assert.ok(dedup.getCommandRecord(command.transaction_id)?.responseDeliveredAt);

  setConnected(false);
  transport.simulateBrokerDrop();
  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      ...command,
      message_id: 'msg-delivered-duplicate',
      timestamp: '2026-04-11T10:00:01Z',
    }),
  );

  assert.equal(bus.flashCalls.length, 1);
  assert.equal(dedup.getCommandRecord(command.transaction_id)?.responseDeliveredAt, undefined);
  assert.equal(
    new FileDedupStore(file).getCommandRecord(command.transaction_id)?.responseDeliveredAt,
    undefined,
  );

  setConnected(true);
  await transport.simulateBrokerRestore();

  assert.equal(bus.flashCalls.length, 1);
  assert.equal(commandResponses(published).length, 2);
  assert.ok(dedup.getCommandRecord(command.transaction_id)?.responseDeliveredAt);
  const responseMessageIds = attempted
    .map((payload) => JSON.parse(payload) as { message_id: string; result?: string })
    .filter((payload) => payload.result === 'success' || payload.result === 'error')
    .map((payload) => payload.message_id);
  assert.equal(new Set(responseMessageIds).size, 3);
});

test('flush rescans when requested while another flush is active', async () => {
  const dedup = new InMemoryDedupStore();
  dedup.markCommandCompleted('txn-first', 'open_compartment', {
    result: 'success',
  });
  let releaseFirstPublish: (() => void) | undefined;
  const firstPublishBlocked = new Promise<void>((resolve) => {
    releaseFirstPublish = resolve;
  });
  let firstPublishStarted: (() => void) | undefined;
  const firstPublishHasStarted = new Promise<void>((resolve) => {
    firstPublishStarted = resolve;
  });
  const publishedTransactions: string[] = [];
  const outbound = new OutboundMqttAdapter(async (_topic, payload) => {
    const transactionId = (JSON.parse(payload) as { transaction_id: string }).transaction_id;
    publishedTransactions.push(transactionId);
    if (transactionId === 'txn-first') {
      firstPublishStarted?.();
      await firstPublishBlocked;
    }
  }, 'locker/test/response');
  const dispatcher = new CommandDispatcher(new InboundProtocolGuard(dedup), outbound, dedup);

  const firstFlush = dispatcher.flushPendingResponses();
  await firstPublishHasStarted;
  dedup.markCommandCompleted('txn-second', 'open_compartment', {
    result: 'success',
  });
  const followUpFlush = dispatcher.flushPendingResponses();
  assert.equal(firstFlush, followUpFlush);
  assert.deepEqual(publishedTransactions, ['txn-first']);

  releaseFirstPublish?.();
  await Promise.all([firstFlush, followUpFlush]);

  assert.deepEqual(publishedTransactions, ['txn-first', 'txn-second']);
  assert.ok(dedup.getCommandRecord('txn-first')?.responseDeliveredAt);
  assert.ok(dedup.getCommandRecord('txn-second')?.responseDeliveredAt);
});

test('apply_config response recovers without applying config twice', async () => {
  const bus = new FakeLockerBus([1]);
  const dedup = new InMemoryDedupStore();
  const published: string[] = [];
  let connected = false;
  const outbound = new OutboundMqttAdapter(async (_topic, payload) => {
    if (!connected) {
      throw new Error('MQTT client is not connected');
    }
    published.push(payload);
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
  let applyCalls = 0;
  const execute = applyConfig.execute.bind(applyConfig);
  applyConfig.execute = async (command) => {
    applyCalls++;
    return execute(command);
  };
  const dispatcher = new CommandDispatcher(new InboundProtocolGuard(dedup), outbound, dedup);
  dispatcher.register(createApplyConfigHandler({ applyConfig }));
  const command = {
    action: 'apply_config',
    transaction_id: 'txn-apply-pending',
    message_id: 'msg-apply-pending',
    timestamp: '2026-04-11T10:00:00Z',
    data: {
      adapter_type: 'waveshare_modbus',
      channel_count: 8,
      feedback_type: 'door_closing',
      config_hash: configHash,
      heartbeat_interval_seconds: 30,
      compartments,
    },
  };

  await dispatcher.dispatch('locker/test/command', JSON.stringify(command));
  connected = true;
  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({ ...command, message_id: 'msg-apply-pending-retry' }),
  );

  assert.equal(applyCalls, 1);
  assert.equal(commandResponses(published).length, 1);
  assert.equal(
    dedup.getCommandRecord('txn-apply-pending')?.response?.applied_config_hash,
    configHash,
  );
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

test('a command arriving during shutdown is refused, not silently dropped', async () => {
  const { bus, dispatcher, published } = createDispatcherHarness();

  dispatcher.beginClosing();

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      message_id: '11111111-1111-1111-1111-111111111111',
      transaction_id: 'tx-shutdown-1',
      timestamp: '2026-04-11T10:00:00Z',
      action: 'open_compartment',
      data: { compartment_number: 1 },
    }),
  );

  const responses = published
    .map((payload) => JSON.parse(payload) as { result?: string; error_code?: string })
    .filter((message) => message.result === 'error');

  assert.equal(responses.length, 1, 'the backend is told, rather than left waiting');
  assert.equal(responses[0].error_code, 'SHUTTING_DOWN');
  assert.equal(bus.flashCalls.length, 0, 'no relay fires once closing has begun');
});

test('commands already running are unaffected by beginClosing', async () => {
  const { bus, dispatcher, published, openCompartment } = createDispatcherHarness();

  const inFlight = dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      message_id: '22222222-2222-2222-2222-222222222222',
      transaction_id: 'tx-inflight-1',
      timestamp: '2026-04-11T10:00:00Z',
      action: 'open_compartment',
      data: { compartment_number: 1 },
    }),
  );

  // Shutdown starts while the open is mid-flight.
  dispatcher.beginClosing();
  await inFlight;

  const responses = published
    .map((payload) => JSON.parse(payload) as { result?: string; transaction_id?: string })
    .filter((message) => message.result === 'success');

  assert.equal(responses.length, 1, 'the in-flight command still answers');
  assert.equal(responses[0].transaction_id, 'tx-inflight-1');
  assert.equal(bus.flashCalls.length, 1, 'and its relay did fire');

  // The real shutdown clears monitoring after draining; without it the door
  // watch keeps the event loop alive here too.
  openCompartment.stopAllMonitoring();
});

test('a redelivery during shutdown replays its stored response instead of refusing', async () => {
  const { dispatcher, published, openCompartment } = createDispatcherHarness();

  const command = JSON.stringify({
    message_id: '33333333-3333-3333-3333-333333333333',
    transaction_id: 'tx-redelivered-1',
    timestamp: '2026-04-11T10:00:00Z',
    action: 'open_compartment',
    data: { compartment_number: 1 },
  });

  // Completed before shutdown; the broker redelivers it after closing begins.
  await dispatcher.dispatch('locker/test/command', command);
  dispatcher.beginClosing();
  await dispatcher.dispatch('locker/test/command', command);

  const responses = published
    .map((payload) => JSON.parse(payload) as { result?: string; error_code?: string })
    .filter((message) => message.result === 'success' || message.result === 'error');

  assert.equal(responses.length, 2, 'the redelivery is answered');
  assert.equal(
    responses[1].result,
    'success',
    'a command whose relay already fired must not come back as an error',
  );
  assert.equal(responses[1].error_code, undefined);

  openCompartment.stopAllMonitoring();
});

test('a command refused during shutdown leaves no in-progress record behind', async () => {
  const dedup = new InMemoryDedupStore();
  const { bus, dispatcher, published } = createDispatcherHarness(new FakeLockerBus([1]), dedup);

  dispatcher.beginClosing();

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      message_id: '44444444-4444-4444-4444-444444444444',
      transaction_id: 'tx-refused-clean',
      timestamp: '2026-04-11T10:00:00Z',
      action: 'open_compartment',
      data: { compartment_number: 1 },
    }),
  );

  assert.equal(bus.flashCalls.length, 0, 'nothing ran');
  assert.equal(
    dedup.getCommandRecord('tx-refused-clean'),
    null,
    'a refused command must not be recorded as work in progress',
  );

  // Restart recovery must have nothing to report: the command already got a
  // clean SHUTTING_DOWN, and a second answer would contradict it.
  const before = published.length;
  dispatcher.recoverInterruptedCommands();
  await dispatcher.flushPendingResponses();
  assert.equal(published.length, before, 'no contradictory answer after restart');
});

test('two concurrent deliveries of one transaction open the door once', async () => {
  const dedup = new InMemoryDedupStore();
  const { bus, dispatcher, published, openCompartment } = createDispatcherHarness(
    new FakeLockerBus([1]),
    dedup,
  );

  // Same transaction, different message ids: only the transaction guard applies,
  // and both arrive before either has finished.
  const deliver = (messageId: string) =>
    dispatcher.dispatch(
      'locker/test/command',
      JSON.stringify({
        message_id: messageId,
        transaction_id: 'tx-concurrent-1',
        timestamp: '2026-04-11T10:00:00Z',
        action: 'open_compartment',
        data: { compartment_number: 1 },
      }),
    );

  await Promise.all([
    deliver('55555555-5555-5555-5555-555555555555'),
    deliver('66666666-6666-6666-6666-666666666666'),
  ]);

  assert.equal(bus.flashCalls.length, 1, 'the relay must fire once for one request');

  const responses = published
    .map((payload) => JSON.parse(payload) as { result?: string })
    .filter((message) => message.result === 'success');
  assert.equal(responses.length, 1, 'and exactly one success is published');

  openCompartment.stopAllMonitoring();
});
