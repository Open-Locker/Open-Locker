import assert from 'node:assert/strict';
import { test } from 'node:test';
import { InMemoryLockerBus, busTargetKey } from '../../src/adapters/simulator/in-memory-locker-bus';

function createBus(initial?: Map<string, 'open' | 'closed' | 'unknown'>) {
  return new InMemoryLockerBus({ slaveIds: [1, 2], initialDoorStates: initial });
}

test('doors default to closed when the scenario does not seed them', async () => {
  const bus = createBus();

  assert.deepEqual(await bus.readDoorSensors(1, 0, 3), ['closed', 'closed', 'closed']);
});

test('seeded door states are returned by block reads', async () => {
  const bus = createBus(
    new Map([
      [busTargetKey(1, 0), 'open' as const],
      [busTargetKey(1, 2), 'unknown' as const],
    ]),
  );

  assert.deepEqual(await bus.readDoorSensors(1, 0, 3), ['open', 'closed', 'unknown']);
});

test('block reads are offset by startAddress, matching the real driver', async () => {
  const bus = createBus(new Map([[busTargetKey(2, 5), 'open' as const]]));

  assert.deepEqual(await bus.readDoorSensors(2, 4, 3), ['closed', 'open', 'closed']);
});

test('flashing a relay pops the door open and it stays open', async () => {
  const bus = createBus();
  const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };

  await bus.flashRelay(target, 10);

  assert.equal(bus.getDoorState(1, 0), 'open');

  await new Promise((resolve) => setTimeout(resolve, 30));

  // The relay pulse ends, but a real door does not close itself.
  assert.equal(await bus.readRelayState(target), false);
  assert.equal(bus.getDoorState(1, 0), 'open');

  await bus.disconnect();
});

test('a door only closes when something closes it', async () => {
  const bus = createBus();

  await bus.flashRelay({ compartmentNumber: 1, slaveId: 1, relayAddress: 0 }, 10);
  bus.setDoorState(1, 0, 'closed');

  assert.equal(bus.getDoorState(1, 0), 'closed');

  await bus.disconnect();
});

test('turnAllRelaysOff only affects the requested board', async () => {
  const bus = createBus();
  const first = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };
  const second = { compartmentNumber: 2, slaveId: 2, relayAddress: 0 };

  await bus.flashRelay(first, 10_000);
  await bus.flashRelay(second, 10_000);

  await bus.turnAllRelaysOff(1);

  assert.equal(await bus.readRelayState(first), false);
  assert.equal(await bus.readRelayState(second), true);

  await bus.disconnect();
});

test('connection lifecycle mirrors the port contract', async () => {
  const bus = createBus();

  assert.equal(bus.getConnectionState(), 'disconnected');

  await bus.connect();
  assert.equal(bus.getConnectionState(), 'connected');
  assert.equal(await bus.ensureConnected(), true);

  await bus.disconnect();
  assert.equal(bus.getConnectionState(), 'disconnected');

  // ensureConnected reconnects rather than failing, as the real actor does.
  assert.equal(await bus.ensureConnected(), true);
  assert.equal(bus.getConnectionState(), 'connected');

  await bus.disconnect();
});

test('configured slave ids are reported from the scenario mapping', () => {
  const bus = createBus();

  assert.deepEqual(bus.getConfiguredSlaveIds(), [1, 2]);
});
