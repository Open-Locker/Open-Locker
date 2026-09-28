import assert from 'node:assert/strict';
import { test } from 'node:test';
import { InMemoryLockerBus, busTargetKey } from '../../src/adapters/simulator/in-memory-locker-bus';

function createBus(initial?: Map<string, 'open' | 'closed' | 'unknown'>) {
  return new InMemoryLockerBus({ boardAddresses: [1, 2], initialDoorStates: initial });
}

test('doors default to closed when the scenario does not seed them', async () => {
  const bus = createBus();

  assert.deepEqual(await bus.readCompartmentStates(1, [0, 1, 2]), ['closed', 'closed', 'closed']);
});

test('seeded door states are returned for the requested addresses', async () => {
  const bus = createBus(
    new Map([
      [busTargetKey(1, 0), 'open' as const],
      [busTargetKey(1, 2), 'unknown' as const],
    ]),
  );

  assert.deepEqual(await bus.readCompartmentStates(1, [0, 1, 2]), ['open', 'closed', 'unknown']);
});

test('reads return states in the order the addresses were requested', async () => {
  const bus = createBus(new Map([[busTargetKey(2, 5), 'open' as const]]));

  assert.deepEqual(await bus.readCompartmentStates(2, [5, 4]), ['open', 'closed']);
});

test('an unlock pops the door open, reports it, and the door stays open', async () => {
  const bus = createBus();
  const target = { compartmentNumber: 1, boardAddress: 1, address: 0 };

  assert.deepEqual(await bus.unlockCompartment(target), { doorState: 'open' });
  assert.equal(bus.getDoorState(1, 0), 'open');

  await new Promise((resolve) => setTimeout(resolve, 30));

  assert.equal(bus.getDoorState(1, 0), 'open');

  await bus.disconnect();
});

test('a door only closes when something closes it', async () => {
  const bus = createBus();

  await bus.unlockCompartment({ compartmentNumber: 1, boardAddress: 1, address: 0 });
  bus.setDoorState(1, 0, 'closed');

  assert.equal(bus.getDoorState(1, 0), 'closed');

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

test('an unlock reconnects a disconnected simulator bus first', async () => {
  const bus = createBus();
  const target = { compartmentNumber: 1, boardAddress: 1, address: 0 };

  await bus.unlockCompartment(target);

  assert.equal(bus.getConnectionState(), 'connected');
  assert.deepEqual(await bus.readCompartmentStates(1, [0]), ['open']);

  await bus.disconnect();
});

test('configured board addresses are reported from the scenario mapping', () => {
  const bus = createBus();

  assert.deepEqual(bus.getConfiguredBoardAddresses(), [1, 2]);
});

// --- jam mode ---

test('a jammed compartment unlocks but its door stays shut', async () => {
  const bus = new InMemoryLockerBus({
    boardAddresses: [1],
    jammedTargets: new Set([busTargetKey(1, 0)]),
  });
  const target = { compartmentNumber: 1, boardAddress: 1, address: 0 };

  assert.deepEqual(await bus.unlockCompartment(target), { doorState: 'closed' });
  assert.deepEqual(await bus.readCompartmentStates(1, [0]), ['closed'], 'the door does not move');
});

test('an unjammed compartment opens on the next unlock', async () => {
  const bus = new InMemoryLockerBus({
    boardAddresses: [1],
    jammedTargets: new Set([busTargetKey(1, 0)]),
  });
  const target = { compartmentNumber: 1, boardAddress: 1, address: 0 };

  await bus.unlockCompartment(target);
  assert.equal(bus.isJammed(1, 0), true);

  bus.setJammed(1, 0, false);
  await bus.unlockCompartment(target);

  assert.equal(bus.isJammed(1, 0), false);
  assert.deepEqual(await bus.readCompartmentStates(1, [0]), ['open']);
});

test('jamming a compartment at runtime stops it opening', async () => {
  const bus = new InMemoryLockerBus({ boardAddresses: [1] });
  const target = { compartmentNumber: 1, boardAddress: 1, address: 0 };

  bus.setJammed(1, 0, true);
  await bus.unlockCompartment(target);

  assert.deepEqual(await bus.readCompartmentStates(1, [0]), ['closed']);
});
