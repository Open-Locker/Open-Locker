import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { test } from 'node:test';
import { wireSimulatedDevice } from '../../src/bootstrap/createSimulatorApp';
import { computeAppliedConfigHash } from '../../src/domain/config-normalization';
import type { SimulatorBankScenario } from '../../src/adapters/simulator/scenario';
import { parseScenario } from '../../src/adapters/simulator/scenario';
import type { OutboundPublishOptions } from '../../src/ports/mqtt.port';
import { assertMatchesSchema } from '../contract/jsonSchema';

const LOCKER_UUID = '11111111-1111-1111-1111-111111111111';

interface CapturedPublish {
  topic: string;
  payload: Record<string, unknown>;
  options?: OutboundPublishOptions;
}

function bankScenario(overrides: Partial<SimulatorBankScenario> = {}): SimulatorBankScenario {
  const scenario = parseScenario({
    banks: [
      {
        name: 'main',
        provisioning_token: 'token-a',
        compartments: [
          { compartment_number: 1, slaveId: 1, address: 0, door_state: 'closed' },
          { compartment_number: 2, slaveId: 1, address: 1, door_state: 'closed' },
        ],
        ...overrides,
      },
    ],
  });

  return scenario.banks[0]!;
}

/** Wires a device against a capturing publish fn — no broker, real code path. */
async function createDeviceUnderTest(bank: SimulatorBankScenario = bankScenario()) {
  const published: CapturedPublish[] = [];

  const wired = wireSimulatedDevice({
    bank,
    lockerUuid: LOCKER_UUID,
    publish: async (topic, payload, options) => {
      published.push({ topic, payload: JSON.parse(payload), options });
    },
    // No background polling: tests drive state changes explicitly so captures
    // stay deterministic.
    pollIntervalMs: null,
  });

  await wired.start();

  return { wired, published };
}

function lastOn(published: CapturedPublish[], topic: string): CapturedPublish {
  const match = published.filter((entry) => entry.topic === topic).at(-1);
  assert.ok(match, `no message published on ${topic}`);

  return match;
}

function openCommand(compartmentNumber: number, transactionId: string) {
  return JSON.stringify({
    action: 'open_compartment',
    message_id: randomUUID(),
    timestamp: new Date().toISOString(),
    transaction_id: transactionId,
    data: { compartment_number: compartmentNumber },
  });
}

test('startup publishes a retained snapshot of the seeded door states', async () => {
  const { wired, published } = await createDeviceUnderTest();

  const snapshot = lastOn(published, wired.topics.snapshot);

  assert.equal(snapshot.options?.retain, true);
  assert.equal(snapshot.options?.qos, 1);
  assert.deepEqual(snapshot.payload.compartments, [
    { compartment_number: 1, door_state: 'closed' },
    { compartment_number: 2, door_state: 'closed' },
  ]);
  assertMatchesSchema('payloads/state-snapshot.json', snapshot.payload);

  await wired.device.shutdown();
});

test('heartbeat publishes on the split state topic, not retained', async () => {
  const { wired, published } = await createDeviceUnderTest();

  const heartbeat = lastOn(published, wired.topics.heartbeat);

  assert.equal(heartbeat.topic, `locker/${LOCKER_UUID}/state/heartbeat`);
  assert.notEqual(heartbeat.options?.retain, true);
  assertMatchesSchema('payloads/state-heartbeat.json', heartbeat.payload);

  await wired.device.shutdown();
});

test('every published message carries message_id and a top-level timestamp', async () => {
  const { wired, published } = await createDeviceUnderTest();

  await wired.dispatch(wired.topics.command, openCommand(1, randomUUID()));

  assert.ok(published.length >= 3);
  for (const entry of published) {
    assert.equal(typeof entry.payload.message_id, 'string');
    assert.equal(typeof entry.payload.timestamp, 'string');
  }

  await wired.device.shutdown();
});

test('open_compartment flips the door open and publishes a fresh snapshot', async () => {
  const { wired, published } = await createDeviceUnderTest();
  const transactionId = randomUUID();

  await wired.dispatch(wired.topics.command, openCommand(1, transactionId));

  const response = lastOn(published, wired.topics.response);
  assert.equal(response.payload.result, 'success');
  assert.equal(response.payload.action, 'open_compartment');
  assert.equal(response.payload.transaction_id, transactionId);
  assertMatchesSchema('payloads/response-command-success.json', response.payload);

  const snapshot = lastOn(published, wired.topics.snapshot);
  assert.deepEqual(snapshot.payload.compartments, [
    { compartment_number: 1, door_state: 'open' },
    { compartment_number: 2, door_state: 'closed' },
  ]);
  assert.equal(wired.device.getDoorState(1), 'open');

  await wired.device.shutdown();
});

test('a repeated transaction_id replays the response and does not open again', async () => {
  const { wired, published } = await createDeviceUnderTest();
  const transactionId = randomUUID();

  await wired.dispatch(wired.topics.command, openCommand(1, transactionId));
  const afterFirst = published.length;
  const firstResponse = lastOn(published, wired.topics.response);

  await wired.dispatch(wired.topics.command, openCommand(1, transactionId));

  const replayed = published.slice(afterFirst);
  assert.equal(replayed.length, 1, 'duplicate must publish exactly one message: the replay');
  assert.equal(
    replayed[0]!.topic,
    wired.topics.response,
    'the only message a duplicate produces is a replayed response',
  );

  // The stored outcome is re-sent verbatim so a backend that missed the first
  // response still gets it; only the technical message id differs.
  const { message_id: _first, ...firstBody } = firstResponse.payload;
  const { message_id: _replay, ...replayBody } = replayed[0]!.payload;
  assert.deepEqual(replayBody, firstBody);

  await wired.device.shutdown();
});

test('opening an unmapped compartment answers with a contract-valid error', async () => {
  const { wired, published } = await createDeviceUnderTest();

  await wired.dispatch(wired.topics.command, openCommand(99, randomUUID()));

  const response = lastOn(published, wired.topics.response);
  assert.equal(response.payload.result, 'error');
  assert.equal(typeof response.payload.error_code, 'string');
  assertMatchesSchema('payloads/response-command-error.json', response.payload);

  await wired.device.shutdown();
});

test('manual door changes publish a new retained snapshot', async () => {
  const { wired, published } = await createDeviceUnderTest();

  await wired.device.setDoorState(2, 'open');

  const snapshot = lastOn(published, wired.topics.snapshot);
  assert.deepEqual(snapshot.payload.compartments, [
    { compartment_number: 1, door_state: 'closed' },
    { compartment_number: 2, door_state: 'open' },
  ]);
  assert.equal(snapshot.options?.retain, true);
  assertMatchesSchema('payloads/state-snapshot.json', snapshot.payload);

  await wired.device.shutdown();
});

test('apply_config remaps compartments and answers with the applied hash', async () => {
  const { wired, published } = await createDeviceUnderTest();

  const compartments = [
    { compartment_number: 7, slaveId: 2, address: 0 },
    { compartment_number: 8, slaveId: 2, address: 1 },
  ];
  const transactionId = randomUUID();

  await wired.dispatch(
    wired.topics.command,
    JSON.stringify({
      action: 'apply_config',
      message_id: randomUUID(),
      timestamp: new Date().toISOString(),
      transaction_id: transactionId,
      data: {
        compartments,
        heartbeat_interval_seconds: 30,
        config_hash: computeAppliedConfigHash(compartments),
      },
    }),
  );

  const response = lastOn(published, wired.topics.response);
  assert.equal(response.payload.result, 'success');
  assert.equal(response.payload.transaction_id, transactionId);
  assert.equal(typeof response.payload.applied_config_hash, 'string');
  assertMatchesSchema('payloads/response-apply-config-success.json', response.payload);

  // The runtime mapping wins over the scenario seed, as on real hardware.
  assert.deepEqual(wired.device.compartmentNumbers, [7, 8]);

  await wired.device.shutdown();
});

test('legacy topics are never published', async () => {
  const { wired, published } = await createDeviceUnderTest();

  await wired.dispatch(wired.topics.command, openCommand(1, randomUUID()));
  await wired.device.setDoorState(1, 'closed');

  for (const entry of published) {
    assert.notEqual(entry.topic, `locker/${LOCKER_UUID}/status`);
    assert.notEqual(entry.topic, `locker/${LOCKER_UUID}/state`);
  }

  await wired.device.shutdown();
});
