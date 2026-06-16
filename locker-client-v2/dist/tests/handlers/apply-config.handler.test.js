"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const apply_config_handler_1 = require("../../src/adapters/mqtt/handlers/apply-config.handler");
const apply_config_1 = require("../../src/application/apply-config");
const outbound_mqtt_adapter_1 = require("../../src/adapters/mqtt/outbound-mqtt.adapter");
const fake_locker_bus_1 = require("../helpers/fake-locker-bus");
const apply_config_2 = require("../../src/application/apply-config");
class MemoryOverlayStore {
    overlay = null;
    load() {
        return this.overlay;
    }
    save(overlay) {
        this.overlay = overlay;
        return overlay;
    }
    clear() {
        this.overlay = null;
    }
}
const compartments = [{ compartment_number: 1, slaveId: 1, address: 0 }];
function createApplyConfigHarness() {
    const bus = new fake_locker_bus_1.FakeLockerBus([1]);
    const overlayStore = new MemoryOverlayStore();
    const config = {
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
        getCompartmentConfig: () => compartments[0],
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
    const published = [];
    const outbound = new outbound_mqtt_adapter_1.OutboundMqttAdapter(async (_topic, payload) => {
        published.push(payload);
    }, 'locker/test/response', () => '2026-06-16T12:00:00.000Z');
    const applyConfig = new apply_config_1.ApplyConfigUseCase({
        overlayStore,
        config,
        bus,
        restartHeartbeat: () => undefined,
        restartPolling: () => undefined,
    });
    const handler = (0, apply_config_handler_1.createApplyConfigHandler)({ applyConfig, outbound });
    return { handler, published, overlayStore };
}
(0, node_test_1.test)('apply_config handler publishes success with applied_config_hash', async () => {
    const { handler, published, overlayStore } = createApplyConfigHarness();
    const configHash = (0, apply_config_2.computeAppliedConfigHash)(compartments);
    await handler.handle({ lockerUuid: 'test' }, {
        action: 'apply_config',
        message_id: 'msg-1',
        transaction_id: 'tx-1',
        timestamp: '2026-06-16T12:00:00.000Z',
        data: {
            config_hash: configHash,
            heartbeat_interval_seconds: 30,
            compartments,
        },
    });
    strict_1.default.ok(overlayStore.load()?.appliedConfigHash);
    strict_1.default.equal(published.length, 1);
    const response = JSON.parse(published[0]);
    strict_1.default.equal(response.action, 'apply_config');
    strict_1.default.equal(response.result, 'success');
    strict_1.default.equal(response.applied_config_hash, configHash);
});
(0, node_test_1.test)('apply_config handler propagates runtime apply failures', async () => {
    const bus = new fake_locker_bus_1.FakeLockerBus([1]);
    bus.reloadRuntimeConfig = async () => {
        throw new Error('modbus reconnect failed');
    };
    const overlayStore = new MemoryOverlayStore();
    const config = {
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
    const handler = (0, apply_config_handler_1.createApplyConfigHandler)({
        applyConfig: new apply_config_1.ApplyConfigUseCase({
            overlayStore,
            config,
            bus,
            restartHeartbeat: () => undefined,
            restartPolling: () => undefined,
        }),
        outbound: new outbound_mqtt_adapter_1.OutboundMqttAdapter(async () => undefined, 'locker/test/response'),
    });
    await strict_1.default.rejects(() => handler.handle({ lockerUuid: 'test' }, {
        action: 'apply_config',
        message_id: 'msg-2',
        transaction_id: 'tx-2',
        timestamp: '2026-06-16T12:00:00.000Z',
        data: {
            config_hash: (0, apply_config_2.computeAppliedConfigHash)(compartments),
            heartbeat_interval_seconds: 30,
            compartments,
        },
    }), /modbus reconnect failed/);
});
