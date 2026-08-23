import assert from 'node:assert/strict';
import { test } from 'node:test';
import { RuntimeConfiguredLockerBus } from '../../src/adapters/runtime/runtime-configured-locker-bus';
import type { EffectiveLockerConfig, HardwareProfile } from '../../src/domain/config';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { createTestConfigRepository } from '../helpers/test-config-repository';

function mutableConfig() {
  let effective: EffectiveLockerConfig = { modbus: { port: '/dev/null' } };
  const base = createTestConfigRepository();
  const port: ConfigRepositoryPort = {
    ...base,
    load: () => effective,
    reload: () => effective,
    getConfiguredSlaveIds: () => [
      ...new Set(effective.compartments?.map((entry) => entry.slaveId) ?? []),
    ],
  };
  return {
    port,
    set(profile: HardwareProfile | undefined, slaveId = 1) {
      effective = {
        modbus: { port: '/dev/null' },
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

  config.set({ adapterType: 'waveshare_modbus', channelCount: 8, feedbackType: 'door_closing' }, 2);
  await bus.reloadRuntimeConfig();
  assert.equal(built[0]?.profile.adapterType, 'waveshare_modbus');
  assert.deepEqual(built[0]?.bus.turnAllOffCalls, [2]);

  config.set(
    { adapterType: 'rs485_lock_board', channelCount: 12, feedbackType: 'door_opening' },
    3,
  );
  await bus.reloadRuntimeConfig();
  assert.equal(built[0]?.bus.getConnectionState(), 'disconnected');
  assert.equal(built[1]?.profile.adapterType, 'rs485_lock_board');
  assert.deepEqual(built[1]?.bus.turnAllOffCalls, [3]);
});

test('waits for an active bus operation before switching adapters', async () => {
  const config = mutableConfig();
  const built: FakeLockerBus[] = [];
  const bus = new RuntimeConfiguredLockerBus(config.port, () => {
    const adapter = new FakeLockerBus();
    built.push(adapter);
    return adapter;
  });
  await bus.connect();
  config.set({ adapterType: 'waveshare_modbus', channelCount: 8, feedbackType: 'door_closing' });
  await bus.reloadRuntimeConfig();

  let releaseFlash!: () => void;
  const flashStarted = new Promise<void>((resolve) => {
    built[0]!.flashRelay = async () => {
      resolve();
      await new Promise<void>((release) => {
        releaseFlash = release;
      });
      return 'pulse_sent';
    };
  });
  const flash = bus.flashRelay({ compartmentNumber: 1, slaveId: 1, relayAddress: 0 }, 200);
  await flashStarted;

  config.set({
    adapterType: 'rs485_lock_board',
    channelCount: 8,
    feedbackType: 'door_closing',
  });
  const reload = bus.reloadRuntimeConfig();
  await Promise.resolve();
  assert.equal(built[0]?.getConnectionState(), 'connected');

  releaseFlash();
  await flash;
  await reload;
  assert.equal(built[0]?.getConnectionState(), 'disconnected');
  assert.equal(built.length, 2);
});
