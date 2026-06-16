import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ApplyConfigUseCase } from '../../src/application/apply-config';
import type { ApplyConfigCommand } from '../../src/domain/mqtt-schemas';
import { computeAppliedConfigHash } from '../../src/application/apply-config';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';
import type { RuntimeOverlayStorePort } from '../../src/ports/config.port';

class MemoryOverlayStore implements RuntimeOverlayStorePort {
  private overlay: import('../../src/domain/config').RuntimeConfigOverlay | null = null;

  load() {
    return this.overlay;
  }

  save(overlay: import('../../src/domain/config').RuntimeConfigOverlay) {
    this.overlay = overlay;
    return overlay;
  }

  clear() {
    this.overlay = null;
  }
}

test('apply config rejects mismatched config_hash', async () => {
  const bus = new FakeLockerBus([1]);
  const overlayStore = new MemoryOverlayStore();

  const config: ConfigRepositoryPort = {
    load: () => ({
      modbus: { port: '/dev/null', flashDurationMs: 200 },
      mqtt: { heartbeatInterval: 15 },
    }),
    reload: () => ({
      modbus: { port: '/dev/null', flashDurationMs: 200 },
      mqtt: { heartbeatInterval: 15 },
    }),
    getCompartmentConfig: () => null,
    hasExplicitRuntimeCompartments: () => false,
    getFlashDurationMs: () => 200,
    getHeartbeatIntervalSeconds: () => 15,
    getMqttTransportSettings: () => ({
      clean: false,
      keepalive: 60,
      reconnectPeriod: 5000,
      connectTimeout: 30000,
      maxReconnectAttempts: 0,
    }),
  };

  const useCase = new ApplyConfigUseCase({
    overlayStore,
    config,
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
      config_hash: 'a'.repeat(64),
      heartbeat_interval_seconds: 30,
      compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
    },
  };

  await assert.rejects(() => useCase.execute(command), /config_hash/);
  assert.equal(overlayStore.load(), null);
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
  const config: ConfigRepositoryPort = {
    load: () => ({
      modbus: { port: '/dev/null', flashDurationMs: 200 },
      mqtt: { heartbeatInterval: 15 },
      compartments: previousOverlay.compartments,
    }),
    reload: () => {
      reloadCount++;
      return {
        modbus: { port: '/dev/null', flashDurationMs: 200 },
        mqtt: { heartbeatInterval: 15 },
        compartments: previousOverlay.compartments,
      };
    },
    getCompartmentConfig: () => previousOverlay.compartments![0]!,
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
  };

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
