import assert from 'node:assert/strict';
import { test } from 'node:test';
import { OpenCompartmentUseCase, runStartupFailsafe } from '../../src/application/open-compartment';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { RunAfterCompleteScheduler } from '../../src/infrastructure/scheduler';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';

function createConfigStub(overrides: Partial<ConfigRepositoryPort> = {}): ConfigRepositoryPort {
  return {
    load: () => ({
      modbus: { port: '/dev/null', flashDurationMs: 200 },
      compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
    }),
    reload: () => ({
      modbus: { port: '/dev/null', flashDurationMs: 200 },
      compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
    }),
    getCompartmentConfig: (n) =>
      n === 1 ? { compartment_number: 1, slaveId: 1, address: 0 } : null,
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
    ...overrides,
  };
}

test('OpenCompartmentUseCase uses hardware flash only', async () => {
  const bus = new FakeLockerBus([1]);
  const useCase = new OpenCompartmentUseCase(
    bus,
    createConfigStub(),
    new RunAfterCompleteScheduler(),
  );

  await useCase.execute(1);
  useCase.stopAllMonitoring();

  assert.equal(bus.flashCalls.length, 1);
  assert.equal(bus.flashCalls[0]?.durationMs, 200);
  assert.equal(bus.writeCoilCalls.length, 0);
});

test('runStartupFailsafe commands all relays off per board', async () => {
  const bus = new FakeLockerBus([1, 2]);
  await runStartupFailsafe(bus);
  assert.deepEqual(bus.turnAllOffCalls, [1, 2]);
});

test('OpenCompartmentUseCase throws when compartment not configured', async () => {
  const bus = new FakeLockerBus([1]);
  const useCase = new OpenCompartmentUseCase(
    bus,
    createConfigStub({
      hasExplicitRuntimeCompartments: () => true,
      getCompartmentConfig: () => null,
    }),
    new RunAfterCompleteScheduler(),
  );

  await assert.rejects(() => useCase.execute(99), /not configured/);
});
