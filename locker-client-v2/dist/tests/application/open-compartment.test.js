"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const open_compartment_1 = require("../../src/application/open-compartment");
const fake_locker_bus_1 = require("../helpers/fake-locker-bus");
const scheduler_1 = require("../../src/infrastructure/scheduler");
function createConfigStub(overrides = {}) {
    return {
        load: () => ({
            modbus: { port: '/dev/null', flashDurationMs: 200 },
            compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
        }),
        reload: () => ({
            modbus: { port: '/dev/null', flashDurationMs: 200 },
            compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
        }),
        getCompartmentConfig: (n) => n === 1 ? { compartment_number: 1, slaveId: 1, address: 0 } : null,
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
(0, node_test_1.test)('OpenCompartmentUseCase uses hardware flash only', async () => {
    const bus = new fake_locker_bus_1.FakeLockerBus([1]);
    const useCase = new open_compartment_1.OpenCompartmentUseCase(bus, createConfigStub(), new scheduler_1.RunAfterCompleteScheduler());
    await useCase.execute(1);
    useCase.stopAllMonitoring();
    strict_1.default.equal(bus.flashCalls.length, 1);
    strict_1.default.equal(bus.flashCalls[0]?.durationMs, 200);
    strict_1.default.equal(bus.writeCoilCalls.length, 0);
});
(0, node_test_1.test)('runStartupFailsafe commands all relays off per board', async () => {
    const bus = new fake_locker_bus_1.FakeLockerBus([1, 2]);
    await (0, open_compartment_1.runStartupFailsafe)(bus);
    strict_1.default.deepEqual(bus.turnAllOffCalls, [1, 2]);
});
(0, node_test_1.test)('OpenCompartmentUseCase throws when compartment not configured', async () => {
    const bus = new fake_locker_bus_1.FakeLockerBus([1]);
    const useCase = new open_compartment_1.OpenCompartmentUseCase(bus, createConfigStub({
        hasExplicitRuntimeCompartments: () => true,
        getCompartmentConfig: () => null,
    }), new scheduler_1.RunAfterCompleteScheduler());
    await strict_1.default.rejects(() => useCase.execute(99), /not configured/);
});
