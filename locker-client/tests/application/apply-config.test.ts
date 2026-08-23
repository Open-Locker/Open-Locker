import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ApplyConfigUseCase } from '../../src/application/apply-config';
import type { ApplyConfigCommand } from '../../src/domain/mqtt-schemas';
import { computeAppliedConfigHash } from '../../src/domain/config-normalization';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import { MemoryOverlayStore } from '../helpers/memory-overlay-store';
import { createTestConfigRepository } from '../helpers/test-config-repository';

test('apply config rejects mismatched config_hash', async () => {
  const bus = new FakeLockerBus([1]);
  const overlayStore = new MemoryOverlayStore();

  const useCase = new ApplyConfigUseCase({
    overlayStore,
    config: createTestConfigRepository(),
    bus,
    restartHeartbeat: () => undefined,
    restartPolling: () => undefined,
  });

  const command: ApplyConfigCommand = {
    action: 'apply_config',
    message_id: 'msg-1',
    transaction_id: 'tx-1',
    timestamp: '2026-06-16T12:00:00.000Z',
    data: {
      adapter_type: 'waveshare_modbus',
      channel_count: 8,
      feedback_type: 'door_closing',
      config_hash: 'a'.repeat(64),
      heartbeat_interval_seconds: 30,
      compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
    },
  };

  await assert.rejects(() => useCase.execute(command), /config_hash/);
  assert.equal(overlayStore.load(), null);
});

test('apply config hashes and persists the complete runtime hardware profile', async () => {
  const overlayStore = new MemoryOverlayStore();
  const compartments = [
    { compartment_number: 2, slaveId: 1, address: 11 },
    { compartment_number: 1, slaveId: 1, address: 0 },
  ];
  const profile = {
    adapter_type: 'rs485_lock_board' as const,
    channel_count: 12 as const,
    feedback_type: 'door_opening' as const,
    compartments,
  };
  const useCase = new ApplyConfigUseCase({
    overlayStore,
    config: createTestConfigRepository(),
    bus: new FakeLockerBus([1]),
    restartHeartbeat: () => undefined,
    restartPolling: () => undefined,
  });

  const result = await useCase.execute({
    action: 'apply_config',
    message_id: 'msg-profile',
    transaction_id: 'tx-profile',
    timestamp: '2026-06-16T12:00:00.000Z',
    data: {
      ...profile,
      config_hash: computeAppliedConfigHash(profile),
      heartbeat_interval_seconds: 30,
    },
  });

  assert.equal(result.appliedConfigHash, computeAppliedConfigHash(profile));
  assert.deepEqual(overlayStore.load()?.hardwareProfile, {
    adapterType: 'rs485_lock_board',
    channelCount: 12,
    feedbackType: 'door_opening',
  });
  assert.deepEqual(
    overlayStore.load()?.compartments?.map((entry) => entry.compartment_number),
    [1, 2],
  );
});

test('apply config rejects unsupported Waveshare channel counts', async () => {
  const compartments = [{ compartment_number: 1, slaveId: 1, address: 0 }];
  const profile = {
    adapter_type: 'waveshare_modbus' as const,
    channel_count: 12 as const,
    feedback_type: 'door_closing' as const,
    compartments,
  };
  const useCase = new ApplyConfigUseCase({
    overlayStore: new MemoryOverlayStore(),
    config: createTestConfigRepository(),
    bus: new FakeLockerBus([1]),
    restartHeartbeat: () => undefined,
    restartPolling: () => undefined,
  });

  await assert.rejects(
    () =>
      useCase.execute({
        action: 'apply_config',
        message_id: 'msg-waveshare-channels',
        transaction_id: 'tx-waveshare-channels',
        timestamp: '2026-08-23T12:00:00.000Z',
        data: {
          ...profile,
          config_hash: computeAppliedConfigHash(profile),
          heartbeat_interval_seconds: 30,
        },
      }),
    /exactly 8 channels/,
  );
});

test('apply config restores previous overlay when runtime reload fails', async () => {
  const previousOverlay = {
    mqtt: { heartbeatInterval: 15 },
    compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
    appliedConfigHash: 'c'.repeat(64),
    updatedAt: '2026-04-11T11:00:00Z',
  };
  const overlayStore = new MemoryOverlayStore();
  overlayStore.save(previousOverlay);

  const bus = new FakeLockerBus([1]);
  let modbusReloadAttempts = 0;
  bus.reloadRuntimeConfig = async () => {
    modbusReloadAttempts++;
    if (modbusReloadAttempts === 1) {
      throw new Error('modbus reconnect failed');
    }
  };

  let reloadCount = 0;
  const config = createTestConfigRepository({
    compartments: previousOverlay.compartments,
    reload: () => {
      reloadCount++;
      return {
        modbus: { port: '/dev/null', flashDurationMs: 200 },
        mqtt: { heartbeatInterval: 15 },
        compartments: previousOverlay.compartments,
      };
    },
  });

  const useCase = new ApplyConfigUseCase({
    overlayStore,
    config,
    bus,
    restartHeartbeat: () => undefined,
    restartPolling: () => undefined,
  });

  const newCompartments = [{ compartment_number: 2, slaveId: 2, address: 1 }];
  const command: ApplyConfigCommand = {
    action: 'apply_config',
    message_id: 'msg-rollback',
    transaction_id: 'tx-rollback',
    timestamp: '2026-04-11T12:00:00Z',
    data: {
      adapter_type: 'waveshare_modbus',
      channel_count: 8,
      feedback_type: 'door_closing',
      config_hash: computeAppliedConfigHash(newCompartments),
      heartbeat_interval_seconds: 45,
      compartments: newCompartments,
    },
  };

  await assert.rejects(() => useCase.execute(command), /modbus reconnect failed/);

  assert.equal(modbusReloadAttempts, 2);
  assert.deepEqual(overlayStore.load(), previousOverlay);
  assert.ok(reloadCount >= 2);
});
