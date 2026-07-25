import assert from 'node:assert/strict';
import { test } from 'node:test';
import { wireSimulatedDevice } from '../../src/bootstrap/createSimulatorApp';
import { parseScenario } from '../../src/adapters/simulator/scenario';
import type { DoorState } from '../../src/domain/compartment';
import { assertMatchesSchema } from '../contract/jsonSchema';

const LOCKER_UUID = '11111111-1111-1111-1111-111111111111';

async function deviceUnderTest() {
  const bank = parseScenario({
    banks: [
      {
        name: 'main',
        provisioning_token: 'token-a',
        compartments: [{ compartment_number: 1, slaveId: 1, address: 0, door_state: 'open' }],
      },
    ],
  }).banks[0]!;

  const published: { topic: string; payload: Record<string, unknown> }[] = [];

  const wired = wireSimulatedDevice({
    bank,
    lockerUuid: LOCKER_UUID,
    publish: async (topic, payload) => {
      published.push({ topic, payload: JSON.parse(payload) });
    },
    pollIntervalMs: null,
  });

  await wired.start();

  return { wired, published };
}

test('a closed door publishes the contract value "closed", never the verb "close"', async () => {
  const { wired, published } = await deviceUnderTest();

  await wired.device.setDoorState(1, 'closed');

  const snapshot = published.filter((entry) => entry.topic === wired.topics.snapshot).at(-1)!;

  assert.deepEqual(snapshot.payload.compartments, [
    { compartment_number: 1, door_state: 'closed' },
  ]);
  assertMatchesSchema('payloads/state-snapshot.json', snapshot.payload);

  await wired.device.shutdown();
});

test('every contract door state round-trips and validates', async () => {
  const { wired, published } = await deviceUnderTest();

  for (const state of ['closed', 'unknown', 'open'] as DoorState[]) {
    await wired.device.setDoorState(1, state);

    const snapshot = published.filter((entry) => entry.topic === wired.topics.snapshot).at(-1)!;

    assert.equal(wired.device.getDoorState(1), state);
    assertMatchesSchema('payloads/state-snapshot.json', snapshot.payload);
  }

  await wired.device.shutdown();
});

test('a door state outside the contract enum is rejected, not published', async () => {
  const { wired, published } = await deviceUnderTest();
  const before = published.length;

  await assert.rejects(
    () => wired.device.setDoorState(1, 'close' as DoorState),
    /Invalid door state "close"/,
  );

  assert.equal(published.length, before, 'nothing may be published for an invalid state');

  await wired.device.shutdown();
});
