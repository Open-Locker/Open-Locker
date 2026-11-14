import assert from 'node:assert/strict';
import { test } from 'node:test';
import { RuntimeConfiguredLockerBus } from '../../src/adapters/runtime/runtime-configured-locker-bus';
import type { EffectiveLockerConfig, HardwareProfile } from '../../src/domain/config';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { createTestConfigRepository } from '../helpers/test-config-repository';

function mutableConfig() {
  let effective: EffectiveLockerConfig = { serial: { port: '/dev/null' } };
  const base = createTestConfigRepository();
  const port: ConfigRepositoryPort = {
    ...base,
    load: () => effective,
    reload: () => effective,
    getConfiguredBoardAddresses: () => [
      ...new Set(effective.compartments?.map((entry) => entry.slaveId) ?? []),
    ],
  };
  return {
    port,
    set(profile: HardwareProfile | undefined, slaveId = 1) {
      effective = {
        serial: { port: '/dev/null' },
        hardwareProfile: profile,
        ...(profile ? { compartments: [{ compartment_number: 1, slaveId, address: 0 }] } : {}),
      };
    },
  };
}

test('starts capability-neutral without constructing a hardware adapter', async () => {
  const config = mutableConfig();
  let factoryCalls = 0;
  const bus = new RuntimeConfiguredLockerBus(config.port, () => {
    factoryCalls++;
    return new FakeLockerBus();
  });

  await bus.connect();
  assert.equal(factoryCalls, 0);
  assert.equal(await bus.ensureConnected(), false);
  assert.equal(bus.getConnectionState(), 'disconnected');
});

test('constructs and reconnects the matching adapter when profile changes', async () => {
  const config = mutableConfig();
  const built: Array<{ profile: HardwareProfile; bus: FakeLockerBus }> = [];
  const bus = new RuntimeConfiguredLockerBus(config.port, (profile) => {
    const adapter = new FakeLockerBus();
    built.push({ profile, bus: adapter });
    return adapter;
  });
  await bus.connect();

  config.set({ adapterType: 'rs485_lock_board', feedbackType: 'door_closing' }, 2);
  await bus.reloadRuntimeConfig();
  assert.equal(built[0]?.profile.adapterType, 'rs485_lock_board');
  assert.deepEqual(boardsRead(built[0]?.bus), [2]);

  config.set({ adapterType: 'rs485_lock_board', feedbackType: 'door_opening' }, 3);
  await bus.reloadRuntimeConfig();
  assert.equal(built[0]?.bus.getConnectionState(), 'disconnected');
  assert.equal(built[1]?.profile.adapterType, 'rs485_lock_board');
  assert.deepEqual(boardsRead(built[1]?.bus), [3]);
});

test('keeps a multi-step exclusive operation on one adapter before switching', async () => {
  const config = mutableConfig();
  const built: FakeLockerBus[] = [];
  const bus = new RuntimeConfiguredLockerBus(config.port, () => {
    const adapter = new FakeLockerBus();
    built.push(adapter);
    return adapter;
  });
  await bus.connect();
  config.set({ adapterType: 'rs485_lock_board', feedbackType: 'door_closing' });
  await bus.reloadRuntimeConfig();

  let notifyOperationStarted!: () => void;
  const operationStarted = new Promise<void>((resolve) => {
    notifyOperationStarted = resolve;
  });
  let releaseOperation!: () => void;
  const operationGate = new Promise<void>((resolve) => {
    releaseOperation = resolve;
  });
  const exclusiveOperation = bus.runExclusive(async (activeBus) => {
    assert.equal(await activeBus.ensureConnected(), true);
    notifyOperationStarted();
    await operationGate;
    return activeBus.unlockCompartment({ compartmentNumber: 1, boardAddress: 1, address: 0 });
  });
  await operationStarted;

  config.set({
    adapterType: 'rs485_lock_board',
    feedbackType: 'door_opening',
  });
  const reload = bus.reloadRuntimeConfig();
  await Promise.resolve();
  assert.equal(built[0]?.getConnectionState(), 'connected');

  releaseOperation();
  await exclusiveOperation;
  await reload;
  assert.equal(built[0]?.getConnectionState(), 'disconnected');
  assert.equal(built.length, 2);
});

test('reloadRuntimeConfig succeeds when hardware is unreachable and boards stay silent', async () => {
  const config = mutableConfig();
  config.set({ adapterType: 'rs485_lock_board', feedbackType: 'door_closing' });
  const adapter = new FakeLockerBus([1]);
  adapter.unreachable = true;
  adapter.readCompartmentStates = async () => {
    throw new Error('board did not answer');
  };
  const bus = new RuntimeConfiguredLockerBus(config.port, () => adapter);
  await bus.connect();

  await assert.doesNotReject(bus.reloadRuntimeConfig());
  assert.equal(bus.getConnectionState(), 'unreachable');
  assert.deepEqual(adapter.doorReads, []);
});

test('reloadRuntimeConfig fails when the bus is connected but every board stays silent', async () => {
  const config = mutableConfig();
  config.set({ adapterType: 'rs485_lock_board', feedbackType: 'door_closing' });
  const adapter = new FakeLockerBus([1]);
  const bus = new RuntimeConfiguredLockerBus(config.port, () => adapter);
  await bus.connect();
  await bus.reloadRuntimeConfig();

  adapter.readCompartmentStates = async () => {
    throw new Error('board did not answer');
  };
  await assert.rejects(bus.reloadRuntimeConfig(), /No configured board answered/);
});

test('reloadRuntimeConfig initializes boards after hardware becomes reachable again', async () => {
  const config = mutableConfig();
  config.set({ adapterType: 'rs485_lock_board', feedbackType: 'door_closing' });
  const adapter = new FakeLockerBus([1]);
  adapter.unreachable = true;
  adapter.readCompartmentStates = async () => {
    throw new Error('board did not answer');
  };
  const bus = new RuntimeConfiguredLockerBus(config.port, () => adapter);
  await bus.connect();
  await bus.reloadRuntimeConfig();

  adapter.unreachable = false;
  adapter.readCompartmentStates = FakeLockerBus.prototype.readCompartmentStates;
  await bus.reloadRuntimeConfig();

  assert.equal(bus.getConnectionState(), 'connected');
  assert.deepEqual(boardsRead(adapter), [1]);
});

test('startup from a stored configuration fails when every board stays silent', async () => {
  const config = mutableConfig();
  config.set({ adapterType: 'rs485_lock_board', feedbackType: 'door_closing' });
  const adapter = new FakeLockerBus([1]);
  adapter.readCompartmentStates = async () => {
    throw new Error('board did not answer');
  };
  const bus = new RuntimeConfiguredLockerBus(config.port, () => adapter);

  await assert.rejects(bus.connect(), /No configured board answered/);
});

test('startup without a hardware profile selects no adapter', async () => {
  const config = mutableConfig();
  let factoryCalls = 0;
  const bus = new RuntimeConfiguredLockerBus(config.port, () => {
    factoryCalls++;
    return new FakeLockerBus();
  });

  await bus.connect();

  assert.equal(factoryCalls, 0);
  await assert.rejects(
    bus.unlockCompartment({ compartmentNumber: 1, boardAddress: 1, address: 0 }),
    /apply_config/,
  );
});

test('an unlock overtakes door reads already queued behind a running operation', async () => {
  const config = mutableConfig();
  config.set({ adapterType: 'rs485_lock_board', feedbackType: 'door_closing' });
  const adapter = new FakeLockerBus([1]);
  const bus = new RuntimeConfiguredLockerBus(config.port, () => adapter);
  await bus.connect();

  const order: string[] = [];
  let releaseBlocker!: () => void;
  const blockerGate = new Promise<void>((resolve) => {
    releaseBlocker = resolve;
  });
  const blocker = bus.readCompartmentStates(1, [0]).then(() => undefined);
  adapter.readCompartmentStates = async () => {
    order.push('read');
    return ['closed'];
  };
  adapter.unlockCompartment = async () => {
    order.push('unlock');
    return {};
  };
  const running = bus.runExclusive(async () => {
    await blockerGate;
  });
  const reads = [bus.readCompartmentStates(1, [0]), bus.readCompartmentStates(1, [0])];
  const unlock = bus.unlockCompartment({ compartmentNumber: 1, boardAddress: 1, address: 0 });

  releaseBlocker();
  await Promise.all([blocker, running, ...reads, unlock]);

  assert.deepEqual(order, ['unlock', 'read', 'read']);
});

function boardsRead(adapter: FakeLockerBus | undefined): number[] {
  return adapter?.doorReads.map((read) => read.boardAddress) ?? [];
}
