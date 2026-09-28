import assert from 'node:assert/strict';
import { test } from 'node:test';
import { OpenCompartmentUseCase } from '../../src/application/open-compartment';
import { ActuationLog } from '../../src/domain/door-detection';
import { UnlockNotSentError, UnlockOutcomeUnknownError } from '../../src/domain/errors';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { FakeDoorEventPublisher } from '../helpers/fake-door-event-publisher';
import { ManualScheduler } from '../helpers/manual-scheduler';
import { RunAfterCompleteScheduler } from '../../src/infrastructure/scheduler';
import { createTestConfigRepository } from '../helpers/test-config-repository';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';

const ONE_COMPARTMENT = [{ compartment_number: 1, slaveId: 1, address: 0 }];
const TARGET = { compartmentNumber: 1, boardAddress: 1, address: 0 };

/** Door detection shares the scheduler queue, so tests drain it until an outcome appears. */
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
  const actuationLog = new ActuationLog();
  const scheduler = overrides.scheduler ?? new ManualScheduler();

  const useCase = new OpenCompartmentUseCase({
    bus,
    config: overrides.config ?? createTestConfigRepository({ compartments: ONE_COMPARTMENT }),
    scheduler,
    doorEvents,
    actuationLog,
    now: overrides.now,
  });

  return { bus, doorEvents, actuationLog, scheduler, useCase };
}

test('unlocks the configured compartment without reading the door first', async () => {
  const { bus, useCase } = build();

  await useCase.execute(1, 'txn-immediate');

  assert.deepEqual(bus.unlockCalls, [TARGET]);
  assert.deepEqual(bus.doorReads, []);
});

test('does not record an actuation or start detection when the unlock was not sent', async () => {
  const bus = new FakeLockerBus([1]);
  bus.unlockCompartment = async () => {
    throw new UnlockNotSentError('port closed');
  };
  const { doorEvents, actuationLog, scheduler, useCase } = build({ bus });

  await assert.rejects(() => useCase.execute(1, 'txn-failed'), /port closed/);

  assert.equal(actuationLog.lastActuationAt(1), null);
  assert.equal(actuationLog.isDetecting(1), false);
  assert.deepEqual(doorEvents.detections, []);
  assert.equal(await (scheduler as ManualScheduler).runNext(), false);
});

test('records a possible actuation but starts no detection when the unlock outcome is unknown', async () => {
  const bus = new FakeLockerBus([1]);
  bus.unlockCompartment = async () => {
    throw new UnlockOutcomeUnknownError('response timed out');
  };
  const { doorEvents, actuationLog, scheduler, useCase } = build({ bus, now: () => 777 });

  await assert.rejects(() => useCase.execute(1, 'txn-unknown'), /response timed out/);

  assert.equal(actuationLog.lastActuationAt(1), 777);
  assert.equal(actuationLog.isDetecting(1), false);
  assert.deepEqual(doorEvents.detections, []);
  assert.equal(await (scheduler as ManualScheduler).runNext(), false);
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

test('a slow unlock does not use up the detection window', async () => {
  let nowMs = 0;
  const bus = new FakeLockerBus([1]);
  bus.unlockCompartment = async () => {
    nowMs = 20_000; // e.g. a reconnect before the unlock reached the board
    return {};
  };
  const { doorEvents, scheduler, useCase } = build({ bus, now: () => nowMs });

  await useCase.execute(1, 'txn-slow');
  await tickUntilOutcome(scheduler as ManualScheduler, doorEvents, 1);
  assert.deepEqual(doorEvents.detections, [], 'still within the window after a slow unlock');

  bus.setDoorState(TARGET, 'open');
  nowMs = 21_000;
  await tickUntilOutcome(scheduler as ManualScheduler, doorEvents);

  assert.deepEqual(doorEvents.lastDetection(), {
    compartmentNumber: 1,
    transactionId: 'txn-slow',
    outcome: 'opened',
    detectionMs: 21_000,
  });
});

test('stops door detection when apply_config remaps the compartment', async () => {
  let compartments = ONE_COMPARTMENT;
  const baseConfig = createTestConfigRepository({ compartments });
  const config: ConfigRepositoryPort = {
    ...baseConfig,
    load: () => ({
      ...baseConfig.load(),
      compartments,
    }),
  };
  const { doorEvents, actuationLog, scheduler, useCase } = build({ config });

  await useCase.execute(1, 'txn-remapped');
  compartments = [{ compartment_number: 1, slaveId: 2, address: 1 }];
  await (scheduler as ManualScheduler).drain(5);

  assert.deepEqual(doorEvents.detections, []);
  assert.equal(actuationLog.isDetecting(1), false);
});

test('actuates before door monitoring even when the door was already open', async () => {
  const bus = new FakeLockerBus([1]);
  bus.setDoorState(TARGET, 'open');
  const { doorEvents, scheduler, useCase } = build({ bus });

  await useCase.execute(1, 'txn-already');
  assert.equal(bus.unlockCalls.length, 1);

  await tickUntilOutcome(scheduler as ManualScheduler, doorEvents);
  assert.equal(doorEvents.lastDetection()?.outcome, 'opened');
});

test('reports opened straight from an unlock response that observed the door open', async () => {
  let nowMs = 0;
  const bus = new FakeLockerBus([1]);
  bus.unlockCompartment = async () => {
    nowMs = 520;
    return { doorState: 'open' };
  };
  const { actuationLog, doorEvents, scheduler, useCase } = build({ bus, now: () => nowMs });

  await useCase.execute(1, 'txn-feedback');
  assert.deepEqual(doorEvents.detections, [], 'the outcome follows the command response');
  nowMs = 9999;
  await (scheduler as ManualScheduler).runNext();

  assert.deepEqual(doorEvents.lastDetection(), {
    compartmentNumber: 1,
    transactionId: 'txn-feedback',
    outcome: 'opened',
    detectionMs: 520,
  });
  assert.equal(actuationLog.isDetecting(1), false);
  assert.equal(await (scheduler as ManualScheduler).runNext(), false, 'no polling needed');
});

test('keeps polling when the unlock response observed the door closed', async () => {
  const bus = new FakeLockerBus([1]);
  bus.unlockObservation = { doorState: 'closed' };
  const { doorEvents, scheduler, useCase } = build({ bus });

  await useCase.execute(1, 'txn-closed');
  assert.deepEqual(doorEvents.detections, []);

  bus.setDoorState(TARGET, 'open');
  await tickUntilOutcome(scheduler as ManualScheduler, doorEvents);
  assert.equal(doorEvents.lastDetection()?.outcome, 'opened');
});

test('records the actuation so a later door opening can be attributed', async () => {
  let nowMs = 4242;
  const { actuationLog, useCase } = build({ now: () => nowMs });

  await useCase.execute(1, 'txn-fire');

  assert.equal(actuationLog.lastActuationAt(1), 4242);
  assert.equal(actuationLog.isDetecting(1), true);
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
