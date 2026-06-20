import assert from 'node:assert/strict';
import { test } from 'node:test';
import { OpenCompartmentUseCase, runStartupFailsafe } from '../../src/application/open-compartment';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { RunAfterCompleteScheduler } from '../../src/infrastructure/scheduler';
import { createTestConfigRepository } from '../helpers/test-config-repository';

test('OpenCompartmentUseCase uses hardware flash only', async () => {
  const bus = new FakeLockerBus([1]);
  const useCase = new OpenCompartmentUseCase(
    bus,
    createTestConfigRepository({
      compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
    }),
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

test('runStartupFailsafe skips boards when no runtime mapping exists', async () => {
  const bus = new FakeLockerBus([]);
  await runStartupFailsafe(bus);
  assert.deepEqual(bus.turnAllOffCalls, []);
});

test('OpenCompartmentUseCase throws when runtime mapping is missing', async () => {
  const bus = new FakeLockerBus([]);
  const useCase = new OpenCompartmentUseCase(
    bus,
    createTestConfigRepository(),
    new RunAfterCompleteScheduler(),
  );

  await assert.rejects(
    () => useCase.execute(1),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /apply_config/);
      return true;
    },
  );
});

test('OpenCompartmentUseCase throws when compartment is not configured', async () => {
  const bus = new FakeLockerBus([1]);
  const useCase = new OpenCompartmentUseCase(
    bus,
    createTestConfigRepository({
      compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
      getCompartmentConfig: () => null,
    }),
    new RunAfterCompleteScheduler(),
  );

  await assert.rejects(() => useCase.execute(99), /not configured/);
});
