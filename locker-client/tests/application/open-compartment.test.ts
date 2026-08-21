import assert from 'node:assert/strict';
import { test } from 'node:test';
import { OpenCompartmentUseCase, runStartupFailsafe } from '../../src/application/open-compartment';
import { RelayFireLog } from '../../src/domain/door-detection';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { FakeDoorEventPublisher } from '../helpers/fake-door-event-publisher';
import { ManualScheduler } from '../helpers/manual-scheduler';
import { RunAfterCompleteScheduler } from '../../src/infrastructure/scheduler';
import { createTestConfigRepository } from '../helpers/test-config-repository';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';

const ONE_COMPARTMENT = [{ compartment_number: 1, slaveId: 1, address: 0 }];
const TARGET = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };

/**
 * Relay monitoring shares the scheduler queue with detection, so tests run
 * queued work until an outcome appears rather than assuming a queue position.
 */
async function tickUntilOutcome(
  scheduler: ManualScheduler,
  doorEvents: FakeDoorEventPublisher,
  maxTicks = 10,
): Promise<void> {
  for (let tick = 0; tick < maxTicks && doorEvents.detections.length === 0; tick++) {
    if (!(await scheduler.runNext())) {
      return;
    }
  }
}

function build(
  overrides: {
    bus?: FakeLockerBus;
    config?: ConfigRepositoryPort;
    scheduler?: ManualScheduler | RunAfterCompleteScheduler;
    now?: () => number;
  } = {},
) {
  const bus = overrides.bus ?? new FakeLockerBus([1]);
  const doorEvents = new FakeDoorEventPublisher();
  const relayFireLog = new RelayFireLog();
  const scheduler = overrides.scheduler ?? new ManualScheduler();

  const useCase = new OpenCompartmentUseCase({
    bus,
    config: overrides.config ?? createTestConfigRepository({ compartments: ONE_COMPARTMENT }),
    scheduler,
    doorEvents,
    relayFireLog,
    now: overrides.now,
  });

  return { bus, doorEvents, relayFireLog, scheduler, useCase };
}

test('OpenCompartmentUseCase uses hardware flash only', async () => {
  const { bus, useCase } = build({ scheduler: new RunAfterCompleteScheduler() });

  await useCase.execute(1, 'txn-1');
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
  const { useCase } = build({
    bus: new FakeLockerBus([]),
    config: createTestConfigRepository(),
  });

  await assert.rejects(
    () => useCase.execute(1, 'txn-1'),
    (error: unknown) => {
      assert.ok(error instanceof Error);
      assert.match(error.message, /apply_config/);
      return true;
    },
  );
});

test('OpenCompartmentUseCase throws when compartment is not configured', async () => {
  const { useCase } = build({
    config: createTestConfigRepository({
      compartments: ONE_COMPARTMENT,
      getCompartmentConfig: () => null,
    }),
  });

  await assert.rejects(() => useCase.execute(99, 'txn-1'), /not configured/);
});

// --- door-open detection ---

test('reports opened with the detection delay once the door moves', async () => {
  let nowMs = 0;
  const { bus, doorEvents, scheduler, useCase } = build({ now: () => nowMs });

  await useCase.execute(1, 'txn-open');
  assert.deepEqual(doorEvents.detections, [], 'no outcome before the door is observed');

  bus.setDoorState(TARGET, 'open');
  nowMs = 1500;
  await tickUntilOutcome(scheduler as ManualScheduler, doorEvents);

  assert.deepEqual(doorEvents.lastDetection(), {
    compartmentNumber: 1,
    transactionId: 'txn-open',
    outcome: 'opened',
    detectionMs: 1500,
  });
});

test('reports door_jammed when the door never opens within the window', async () => {
  let nowMs = 0;
  const { doorEvents, scheduler, useCase } = build({ now: () => nowMs });
  const manual = scheduler as ManualScheduler;

  await useCase.execute(1, 'txn-jam');

  // Door stays closed; ticks continue until the heartbeat-derived window elapses
  // (the test config reports a 15s heartbeat interval).
  nowMs = 5000;
  await tickUntilOutcome(manual, doorEvents, 3);
  assert.deepEqual(doorEvents.detections, [], 'still within the detection window');

  nowMs = 15_000;
  await tickUntilOutcome(manual, doorEvents);

  assert.deepEqual(doorEvents.lastDetection(), {
    compartmentNumber: 1,
    transactionId: 'txn-jam',
    outcome: 'door_jammed',
    detectionMs: null,
  });
});

test('reports already_open without waiting when the door was open before the pulse', async () => {
  const bus = new FakeLockerBus([1]);
  bus.setDoorState(TARGET, 'open');
  const { doorEvents, relayFireLog, useCase } = build({ bus });

  await useCase.execute(1, 'txn-already');

  assert.deepEqual(doorEvents.lastDetection(), {
    compartmentNumber: 1,
    transactionId: 'txn-already',
    outcome: 'already_open',
    detectionMs: null,
  });
  assert.equal(bus.flashCalls.length, 1, 'the relay still fires');
  assert.equal(relayFireLog.isDetecting(1), false, 'no detection window is opened');
});

test('records the relay fire so a later door opening can be attributed', async () => {
  let nowMs = 4242;
  const { relayFireLog, useCase } = build({ now: () => nowMs });

  await useCase.execute(1, 'txn-fire');

  assert.equal(relayFireLog.lastFireAt(1), 4242);
  assert.equal(relayFireLog.isDetecting(1), true);
});

test('a failed detection publish does not throw out of the tick', async () => {
  let nowMs = 0;
  const { bus, doorEvents, scheduler, useCase } = build({ now: () => nowMs });
  doorEvents.publishOpenDetection = async () => {
    throw new Error('broker unavailable');
  };

  await useCase.execute(1, 'txn-publish-fails');
  bus.setDoorState(TARGET, 'open');
  nowMs = 800;

  await assert.doesNotReject(() => (scheduler as ManualScheduler).drain(5));
});
