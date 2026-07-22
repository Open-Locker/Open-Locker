import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  COMPARTMENT_POLL_INTERVAL_MS,
  PollCompartmentStateUseCase,
  type CompartmentSnapshotEntry,
} from '../../src/application/state-publishing';
import type { DoorState } from '../../src/domain/compartment';
import type {
  CommandResponseBody,
  OutboundMqttPort,
  OutboundPublishOptions,
} from '../../src/ports/mqtt.port';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { createTestConfigRepository } from '../helpers/test-config-repository';

const snapshotTopic = 'locker/test/state/compartments';

test('polls each board once and maps all configured addresses from batch reads', async () => {
  const bus = new FakeLockerBus([1, 2]);
  bus.setDoorBatchStates(1, statesWith({ 0: 'closed', 7: 'open' }));
  bus.setDoorBatchStates(2, statesWith({ 2: 'closed' }));
  const outbound = new RecordingOutbound();
  const poll = new PollCompartmentStateUseCase(
    bus,
    createTestConfigRepository({
      compartments: [
        { compartment_number: 1, slaveId: 1, address: 0 },
        { compartment_number: 2, slaveId: 1, address: 7 },
        { compartment_number: 3, slaveId: 2, address: 2 },
      ],
    }),
    outbound,
    snapshotTopic,
  );

  await poll.pollAndPublish();

  assert.deepEqual(bus.doorBatchReads, [
    { slaveId: 1, startAddress: 0, length: 8 },
    { slaveId: 2, startAddress: 2, length: 1 },
  ]);
  assert.deepEqual(outbound.snapshots[0]?.compartments, [
    { compartment_number: 1, door_state: 'closed' },
    { compartment_number: 2, door_state: 'open' },
    { compartment_number: 3, door_state: 'closed' },
  ]);
  assert.deepEqual(outbound.snapshots[0]?.options, { qos: 1, retain: true });
});

test('publishes only state changes while preserving force publish', async () => {
  const bus = new FakeLockerBus([1]);
  bus.setDoorBatchStates(1, statesWith({ 0: 'closed' }));
  const outbound = new RecordingOutbound();
  const poll = createPoll(bus, outbound);

  await poll.pollAndPublish();
  await poll.pollAndPublish();
  assert.equal(outbound.snapshots.length, 1);

  bus.setDoorBatchStates(1, statesWith({ 0: 'open' }));
  await poll.pollAndPublish();
  assert.equal(outbound.snapshots.length, 2);

  await poll.pollAndPublish(true);
  assert.equal(outbound.snapshots.length, 3);
});

test('publishes unknown after three consecutive failures and clears it on recovery', async () => {
  const bus = new FakeLockerBus([1]);
  bus.setDoorBatchStates(1, statesWith({ 0: 'closed' }));
  const outbound = new RecordingOutbound();
  const poll = createPoll(bus, outbound);

  await poll.pollAndPublish();

  bus.setDoorBatchStates(1, statesWith({ 0: 'unknown' }));
  await poll.pollAndPublish();
  await poll.pollAndPublish();
  assert.equal(outbound.snapshots.length, 1);

  await poll.pollAndPublish();
  assert.equal(outbound.snapshots.length, 2);
  assert.equal(outbound.snapshots[1]?.compartments[0]?.door_state, 'unknown');

  bus.setDoorBatchStates(1, statesWith({ 0: 'closed' }));
  await poll.pollAndPublish();
  assert.equal(outbound.snapshots.length, 3);
  assert.equal(outbound.snapshots[2]?.compartments[0]?.door_state, 'closed');
});

test('publishes initial unknown immediately when no known state exists', async () => {
  const bus = new FakeLockerBus([1]);
  bus.setDoorBatchStates(1, statesWith({ 0: 'unknown' }));
  const outbound = new RecordingOutbound();
  const poll = createPoll(bus, outbound);

  await poll.pollAndPublish();

  assert.equal(outbound.snapshots[0]?.compartments[0]?.door_state, 'unknown');
});

test('retries an unchanged snapshot after a failed publish', async () => {
  const bus = new FakeLockerBus([1]);
  const outbound = new RecordingOutbound();
  outbound.failNextPublish = true;
  const poll = createPoll(bus, outbound);

  await poll.pollAndPublish();
  assert.equal(outbound.snapshots.length, 0);

  await poll.pollAndPublish();
  assert.equal(outbound.snapshots.length, 1);
});

test('coalesces force requests during an active poll into one follow-up poll', async () => {
  const bus = new FakeLockerBus([1]);
  const outbound = new RecordingOutbound();
  const poll = createPoll(bus, outbound);
  let releaseFirstRead!: () => void;
  let markFirstReadStarted!: () => void;
  const firstReadStarted = new Promise<void>((resolve) => {
    markFirstReadStarted = resolve;
  });
  const firstReadReleased = new Promise<void>((resolve) => {
    releaseFirstRead = resolve;
  });
  let reads = 0;

  bus.readDoorSensors = async (slaveId, startAddress, length) => {
    bus.doorBatchReads.push({ slaveId, startAddress, length });
    reads++;
    if (reads === 1) {
      markFirstReadStarted();
      await firstReadReleased;
    }
    return statesWith({ 0: 'closed' }).slice(startAddress, startAddress + length);
  };

  const firstPoll = poll.pollAndPublish();
  await firstReadStarted;
  const firstForce = poll.pollAndPublish(true);
  const secondForce = poll.pollAndPublish(true);
  releaseFirstRead();
  await Promise.all([firstPoll, firstForce, secondForce]);

  assert.equal(reads, 2);
  assert.equal(outbound.snapshots.length, 2);
});

test('does not lose a force request in the poll completion microtask window', async () => {
  const bus = new FakeLockerBus([1]);
  const outbound = new RecordingOutbound();
  const poll = createPoll(bus, outbound);
  let completionForce: Promise<void> | undefined;

  outbound.afterPublish = () => {
    outbound.afterPublish = undefined;
    queueNestedMicrotask(3, () => {
      completionForce = poll.pollAndPublish(true);
    });
  };

  await poll.pollAndPublish();
  await new Promise<void>((resolve) => setImmediate(resolve));
  assert.ok(completionForce);
  await completionForce;

  assert.equal(outbound.snapshots.length, 2);
});

test('discards a snapshot when apply_config changes mapping during the read', async () => {
  let compartments = [{ compartment_number: 1, slaveId: 1, address: 0 }];
  const config = createTestConfigRepository({
    compartments,
    load: () => ({
      modbus: { port: '/dev/null', flashDurationMs: 200 },
      compartments,
    }),
  });
  const bus = new FakeLockerBus([1, 2]);
  const outbound = new RecordingOutbound();
  const poll = new PollCompartmentStateUseCase(bus, config, outbound, snapshotTopic);
  let releaseFirstRead!: () => void;
  let markFirstReadStarted!: () => void;
  const firstReadStarted = new Promise<void>((resolve) => {
    markFirstReadStarted = resolve;
  });
  const firstReadReleased = new Promise<void>((resolve) => {
    releaseFirstRead = resolve;
  });

  bus.readDoorSensors = async (slaveId, startAddress, length) => {
    bus.doorBatchReads.push({ slaveId, startAddress, length });
    if (slaveId === 1) {
      markFirstReadStarted();
      await firstReadReleased;
    }
    return statesWith({ 0: 'closed', 1: 'open' }).slice(startAddress, startAddress + length);
  };

  const oldConfigPoll = poll.pollAndPublish();
  await firstReadStarted;
  compartments = [{ compartment_number: 2, slaveId: 2, address: 1 }];
  const configForcePoll = poll.pollAndPublish(true);
  releaseFirstRead();
  await Promise.all([oldConfigPoll, configForcePoll]);

  assert.deepEqual(bus.doorBatchReads, [
    { slaveId: 1, startAddress: 0, length: 1 },
    { slaveId: 2, startAddress: 1, length: 1 },
  ]);
  assert.equal(outbound.snapshots.length, 1);
  assert.deepEqual(outbound.snapshots[0]?.compartments, [
    { compartment_number: 2, door_state: 'open' },
  ]);
});

test('skips overlapping regular poll requests', async () => {
  const bus = new FakeLockerBus([1]);
  const outbound = new RecordingOutbound();
  const poll = createPoll(bus, outbound);
  let releaseRead!: () => void;
  let markReadStarted!: () => void;
  const readStarted = new Promise<void>((resolve) => {
    markReadStarted = resolve;
  });
  const readReleased = new Promise<void>((resolve) => {
    releaseRead = resolve;
  });
  let reads = 0;

  bus.readDoorSensors = async (slaveId, startAddress, length) => {
    bus.doorBatchReads.push({ slaveId, startAddress, length });
    reads++;
    markReadStarted();
    await readReleased;
    return statesWith({ 0: 'closed' }).slice(startAddress, startAddress + length);
  };

  const firstPoll = poll.pollAndPublish();
  await readStarted;
  const overlappingPoll = poll.pollAndPublish();
  releaseRead();
  await Promise.all([firstPoll, overlappingPoll]);

  assert.equal(reads, 1);
  assert.equal(outbound.snapshots.length, 1);
});

test('uses a fixed 500 ms polling interval', () => {
  assert.equal(COMPARTMENT_POLL_INTERVAL_MS, 500);
});

function createPoll(bus: FakeLockerBus, outbound: RecordingOutbound): PollCompartmentStateUseCase {
  return new PollCompartmentStateUseCase(
    bus,
    createTestConfigRepository({
      compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
    }),
    outbound,
    snapshotTopic,
  );
}

function statesWith(overrides: Partial<Record<number, DoorState>>): DoorState[] {
  return Array.from({ length: 8 }, (_, address) => overrides[address] ?? 'closed');
}

function queueNestedMicrotask(depth: number, callback: () => void): void {
  if (depth === 0) {
    callback();
    return;
  }
  queueMicrotask(() => queueNestedMicrotask(depth - 1, callback));
}

class RecordingOutbound implements OutboundMqttPort {
  failNextPublish = false;
  afterPublish: (() => void) | undefined;
  readonly snapshots: Array<{
    topic: string;
    compartments: CompartmentSnapshotEntry[];
    options?: OutboundPublishOptions;
  }> = [];

  async publishJson(
    topic: string,
    body: Record<string, unknown>,
    options?: OutboundPublishOptions,
  ): Promise<void> {
    if (this.failNextPublish) {
      this.failNextPublish = false;
      throw new Error('MQTT unavailable');
    }
    this.snapshots.push({
      topic,
      compartments: body.compartments as CompartmentSnapshotEntry[],
      options,
    });
    this.afterPublish?.();
  }

  async publishCommandResponse(_body: CommandResponseBody): Promise<void> {}
}
