"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const apply_config_1 = require("../../src/application/apply-config");
const apply_config_2 = require("../../src/application/apply-config");
const fake_locker_bus_1 = require("../helpers/fake-locker-bus");
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
(0, node_test_1.test)("apply config rejects mismatched config_hash", async () => {
    const bus = new fake_locker_bus_1.FakeLockerBus([1]);
    const overlayStore = new MemoryOverlayStore();
    let reloadCount = 0;
    const config = {
        load: () => ({
            modbus: { port: "/dev/null", flashDurationMs: 200 },
            mqtt: { heartbeatInterval: 15 },
        }),
        reload: () => {
            reloadCount++;
            return {
                modbus: { port: "/dev/null", flashDurationMs: 200 },
                mqtt: { heartbeatInterval: 15 },
            };
        },
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
    const useCase = new apply_config_1.ApplyConfigUseCase({
        overlayStore,
        config,
        bus,
        restartHeartbeat: () => undefined,
        restartPolling: () => undefined,
    });
    const command = {
        action: "apply_config",
        message_id: "msg-1",
        transaction_id: "tx-1",
        timestamp: "2026-06-16T12:00:00.000Z",
        data: {
            config_hash: "a".repeat(64),
            heartbeat_interval_seconds: 30,
            compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
        },
    };
    await strict_1.default.rejects(() => useCase.execute(command), /config_hash/);
    strict_1.default.equal(overlayStore.load(), null);
});
(0, node_test_1.test)("apply config restores previous overlay when runtime reload fails", async () => {
    const previousOverlay = {
        mqtt: { heartbeatInterval: 15 },
        compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
        appliedConfigHash: "c".repeat(64),
        updatedAt: "2026-04-11T11:00:00Z",
    };
    const overlayStore = new MemoryOverlayStore();
    overlayStore.save(previousOverlay);
    const bus = new fake_locker_bus_1.FakeLockerBus([1]);
    let modbusReloadAttempts = 0;
    bus.reloadRuntimeConfig = async () => {
        modbusReloadAttempts++;
        if (modbusReloadAttempts === 1) {
            throw new Error("modbus reconnect failed");
        }
    };
    let reloadCount = 0;
    const config = {
        load: () => ({
            modbus: { port: "/dev/null", flashDurationMs: 200 },
            mqtt: { heartbeatInterval: 15 },
            compartments: previousOverlay.compartments,
        }),
        reload: () => {
            reloadCount++;
            return {
                modbus: { port: "/dev/null", flashDurationMs: 200 },
                mqtt: { heartbeatInterval: 15 },
                compartments: previousOverlay.compartments,
            };
        },
        getCompartmentConfig: () => previousOverlay.compartments[0],
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
    const useCase = new apply_config_1.ApplyConfigUseCase({
        overlayStore,
        config,
        bus,
        restartHeartbeat: () => undefined,
        restartPolling: () => undefined,
    });
    const newCompartments = [{ compartment_number: 2, slaveId: 2, address: 1 }];
    const command = {
        action: "apply_config",
        message_id: "msg-rollback",
        transaction_id: "tx-rollback",
        timestamp: "2026-04-11T12:00:00Z",
        data: {
            config_hash: (0, apply_config_2.computeAppliedConfigHash)(newCompartments),
            heartbeat_interval_seconds: 45,
            compartments: newCompartments,
        },
    };
    await strict_1.default.rejects(() => useCase.execute(command), /modbus reconnect failed/);
    strict_1.default.equal(modbusReloadAttempts, 2);
    strict_1.default.deepEqual(overlayStore.load(), previousOverlay);
    strict_1.default.ok(reloadCount >= 2);
});
