import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createApplyConfigHandler } from '../../src/adapters/mqtt/handlers/apply-config.handler';
import { ApplyConfigUseCase } from '../../src/application/apply-config';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
import { FakeLockerBus } from '../helpers/fake-locker-bus';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';
import type { RuntimeOverlayStorePort } from '../../src/ports/config.port';
import { computeAppliedConfigHash } from '../../src/application/apply-config';

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

const compartments = [{ compartment_number: 1, slaveId: 1, address: 0 }];

function createApplyConfigHarness() {
  const bus = new FakeLockerBus([1]);
  const overlayStore = new MemoryOverlayStore();
  const config: ConfigRepositoryPort = {
    load: () => ({
      modbus: { port: '/dev/null', flashDurationMs: 200 },
      mqtt: { heartbeatInterval: 15 },
      compartments,
    }),
    reload: () => ({
      modbus: { port: '/dev/null', flashDurationMs: 200 },
      mqtt: { heartbeatInterval: 15 },
      compartments,
    }),
    getCompartmentConfig: () => compartments[0]!,
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

  const published: string[] = [];
  const outbound = new OutboundMqttAdapter(
    async (_topic, payload) => {
      published.push(payload);
    },
    'locker/test/response',
    () => '2026-06-16T12:00:00.000Z',
  );

  const applyConfig = new ApplyConfigUseCase({
    overlayStore,
    config,
    bus,
    restartHeartbeat: () => undefined,
    restartPolling: () => undefined,
  });

  const handler = createApplyConfigHandler({ applyConfig, outbound });

  return { handler, published, overlayStore };
}

test('apply_config handler publishes success with applied_config_hash', async () => {
  const { handler, published, overlayStore } = createApplyConfigHarness();
  const configHash = computeAppliedConfigHash(compartments);

  await handler.handle(
    { lockerUuid: 'test' },
    {
      action: 'apply_config',
      message_id: 'msg-1',
      transaction_id: 'tx-1',
      timestamp: '2026-06-16T12:00:00.000Z',
      data: {
        config_hash: configHash,
        heartbeat_interval_seconds: 30,
        compartments,
      },
    },
  );

  assert.ok(overlayStore.load()?.appliedConfigHash);
  assert.equal(published.length, 1);
  const response = JSON.parse(published[0]!) as {
    action: string;
    result: string;
    applied_config_hash: string;
  };
  assert.equal(response.action, 'apply_config');
  assert.equal(response.result, 'success');
  assert.equal(response.applied_config_hash, configHash);
});

test('apply_config handler propagates runtime apply failures', async () => {
  const bus = new FakeLockerBus([1]);
  bus.reloadRuntimeConfig = async () => {
    throw new Error('modbus reconnect failed');
  };

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

  const handler = createApplyConfigHandler({
    applyConfig: new ApplyConfigUseCase({
      overlayStore,
      config,
      bus,
      restartHeartbeat: () => undefined,
      restartPolling: () => undefined,
    }),
    outbound: new OutboundMqttAdapter(async () => undefined, 'locker/test/response'),
  });

  await assert.rejects(
    () =>
      handler.handle(
        { lockerUuid: 'test' },
        {
          action: 'apply_config',
          message_id: 'msg-2',
          transaction_id: 'tx-2',
          timestamp: '2026-06-16T12:00:00.000Z',
          data: {
            config_hash: computeAppliedConfigHash(compartments),
            heartbeat_interval_seconds: 30,
            compartments,
          },
        },
      ),
    /modbus reconnect failed/,
  );
});
