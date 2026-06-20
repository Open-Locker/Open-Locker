import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CommandDispatcher } from '../../src/adapters/mqtt/command-dispatcher';
import { InboundProtocolGuard } from '../../src/adapters/mqtt/inbound-protocol-guard';
import { InMemoryDedupStore } from '../../src/adapters/mqtt/dedup-store';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
import { createOpenCompartmentHandler } from '../../src/adapters/mqtt/handlers/open-compartment.handler';
import { createApplyConfigHandler } from '../../src/adapters/mqtt/handlers/apply-config.handler';
import { OpenCompartmentUseCase } from '../../src/application/open-compartment';
import { ApplyConfigUseCase } from '../../src/application/apply-config';
import { PollCompartmentStateUseCase } from '../../src/application/state-publishing';
import { RunAfterCompleteScheduler } from '../../src/infrastructure/scheduler';
import { computeAppliedConfigHash } from '../../src/domain/config-normalization';
import { parseProvisioningResponse } from '../../src/application/provision-device';
import { knownMQTTCommandSchema } from '../../src/domain/mqtt-schemas';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { MemoryOverlayStore } from '../helpers/memory-overlay-store';
import { createTestConfigRepository } from '../helpers/test-config-repository';
import { assertMatchesSchema, readAsyncApiExample } from './jsonSchema';

const compartments = [{ compartment_number: 1, slaveId: 1, address: 0 }];

function parsePublishedPayload(payload: string): Record<string, unknown> {
  return JSON.parse(payload) as Record<string, unknown>;
}

function isCommandResponse(message: Record<string, unknown>): boolean {
  return message.result === 'success' || message.result === 'error';
}

test('handler-built open_compartment success matches AsyncAPI schema', async () => {
  const bus = new FakeLockerBus([1]);
  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      published.push(payload);
    },
    'locker/test/response',
    () => '2026-04-14T19:30:01Z',
  );
  const config = createTestConfigRepository({ compartments });
  const openCompartment = new OpenCompartmentUseCase(bus, config, new RunAfterCompleteScheduler());
  const pollSnapshot = new PollCompartmentStateUseCase(
    bus,
    config,
    outbound,
    'locker/test/state/compartments',
  );
  const handler = createOpenCompartmentHandler({ openCompartment, outbound, pollSnapshot });

  await handler.handle(
    { lockerUuid: 'test' },
    {
      action: 'open_compartment',
      message_id: 'msg-1',
      transaction_id: 'txn-open-001',
      timestamp: '2026-04-14T19:30:00Z',
      data: { compartment_number: 1 },
    },
  );

  openCompartment.stopAllMonitoring();

  const response = published
    .map(parsePublishedPayload)
    .find((message) => message.result === 'success');
  assert.ok(response);
  assertMatchesSchema('payloads/response-command-success.json', response);
});

test('handler-built apply_config success matches AsyncAPI schema', async () => {
  const bus = new FakeLockerBus([1]);
  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      published.push(payload);
    },
    'locker/test/response',
    () => '2026-04-14T19:31:02Z',
  );
  const configHash = computeAppliedConfigHash(compartments);
  const applyConfig = new ApplyConfigUseCase({
    overlayStore: new MemoryOverlayStore(),
    config: createTestConfigRepository({ compartments }),
    bus,
    restartHeartbeat: () => undefined,
    restartPolling: () => undefined,
  });
  const handler = createApplyConfigHandler({ applyConfig, outbound });

  await handler.handle(
    { lockerUuid: 'test' },
    {
      action: 'apply_config',
      message_id: 'msg-1',
      transaction_id: 'txn-config-001',
      timestamp: '2026-04-14T19:31:00Z',
      data: {
        config_hash: configHash,
        heartbeat_interval_seconds: 30,
        compartments,
      },
    },
  );

  const response = published
    .map(parsePublishedPayload)
    .find((message) => message.result === 'success');
  assert.ok(response);
  assertMatchesSchema('payloads/response-apply-config-success.json', response);
});

test('dispatcher-built validation error matches AsyncAPI schema', async () => {
  const bus = new FakeLockerBus([1]);
  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      published.push(payload);
    },
    'locker/test/response',
    () => '2026-04-14T19:30:01Z',
  );
  const config = createTestConfigRepository({ compartments });
  const openCompartment = new OpenCompartmentUseCase(bus, config, new RunAfterCompleteScheduler());
  const pollSnapshot = new PollCompartmentStateUseCase(
    bus,
    config,
    outbound,
    'locker/test/state/compartments',
  );
  const dedup = new InMemoryDedupStore();
  const dispatcher = new CommandDispatcher(new InboundProtocolGuard(dedup), outbound, dedup);
  dispatcher.register(
    createOpenCompartmentHandler({ openCompartment, outbound, pollSnapshot }),
  );

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-open-001',
      message_id: 'msg-invalid',
      timestamp: '2026-04-14T19:30:00Z',
      data: { compartment_number: 0 },
    }),
  );

  openCompartment.stopAllMonitoring();

  const response = published.map(parsePublishedPayload).find(isCommandResponse);
  assert.ok(response);
  assertMatchesSchema('payloads/response-command-error.json', response);
});

test('dispatcher-built handler error matches AsyncAPI schema', async () => {
  const bus = new FakeLockerBus([1]);
  bus.flashRelay = async () => {
    throw new Error('door jammed');
  };

  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      published.push(payload);
    },
    'locker/test/response',
    () => '2026-04-14T19:30:01Z',
  );
  const config = createTestConfigRepository({ compartments });
  const openCompartment = new OpenCompartmentUseCase(bus, config, new RunAfterCompleteScheduler());
  const pollSnapshot = new PollCompartmentStateUseCase(
    bus,
    config,
    outbound,
    'locker/test/state/compartments',
  );
  const dedup = new InMemoryDedupStore();
  const dispatcher = new CommandDispatcher(new InboundProtocolGuard(dedup), outbound, dedup);
  dispatcher.register(
    createOpenCompartmentHandler({ openCompartment, outbound, pollSnapshot }),
  );

  await dispatcher.dispatch(
    'locker/test/command',
    JSON.stringify({
      action: 'open_compartment',
      transaction_id: 'txn-open-001',
      message_id: 'msg-fail',
      timestamp: '2026-04-14T19:30:00Z',
      data: { compartment_number: 1 },
    }),
  );

  openCompartment.stopAllMonitoring();

  const response = published.map(parsePublishedPayload).find(isCommandResponse);
  assert.ok(response);
  assertMatchesSchema('payloads/response-command-error.json', response);
});

test('handler-built compartment snapshot matches AsyncAPI schema', async () => {
  const bus = new FakeLockerBus([1]);
  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      published.push(payload);
    },
    'locker/test/response',
    () => '2026-04-14T19:36:05Z',
  );
  const config = createTestConfigRepository({ compartments });
  const pollSnapshot = new PollCompartmentStateUseCase(
    bus,
    config,
    outbound,
    'locker/test/state/compartments',
  );

  await pollSnapshot.pollAndPublish(true);

  const snapshot = published.map(parsePublishedPayload).find((message) => 'compartments' in message);
  assert.ok(snapshot);
  assertMatchesSchema('payloads/state-snapshot.json', snapshot);
});

test('every AsyncAPI inbound command example validates with runtime Zod schema', () => {
  for (const exampleFile of ['command-open-compartment.json', 'command-apply-config.json'] as const) {
    const example = readAsyncApiExample(exampleFile);
    assert.equal(
      knownMQTTCommandSchema.safeParse(example).success,
      true,
      `${exampleFile} must validate with knownMQTTCommandSchema`,
    );
  }
});

test('provisioning reply parser accepts AsyncAPI success and error examples', () => {
  const success = readAsyncApiExample('provisioning-success.json');
  const error = readAsyncApiExample('provisioning-error.json');

  assertMatchesSchema('payloads/provisioning-success.json', success);
  assertMatchesSchema('payloads/provisioning-error.json', error);
  assert.equal(parseProvisioningResponse(success).status, 'success');
  assert.equal(parseProvisioningResponse(error).status, 'error');
});
