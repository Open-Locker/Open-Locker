"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const command_dispatcher_1 = require("../../src/adapters/mqtt/command-dispatcher");
const inbound_protocol_guard_1 = require("../../src/adapters/mqtt/inbound-protocol-guard");
const dedup_store_1 = require("../../src/adapters/mqtt/dedup-store");
const outbound_mqtt_adapter_1 = require("../../src/adapters/mqtt/outbound-mqtt.adapter");
const open_compartment_handler_1 = require("../../src/adapters/mqtt/handlers/open-compartment.handler");
const open_compartment_1 = require("../../src/application/open-compartment");
const state_publishing_1 = require("../../src/application/state-publishing");
const scheduler_1 = require("../../src/infrastructure/scheduler");
const fake_locker_bus_1 = require("../helpers/fake-locker-bus");
const configStub = {
    load: () => ({
        modbus: { port: "/dev/null", flashDurationMs: 200 },
        compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
    }),
    reload: () => ({
        modbus: { port: "/dev/null", flashDurationMs: 200 },
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
};
function createDispatcherHarness() {
    const bus = new fake_locker_bus_1.FakeLockerBus([1]);
    const dedup = new dedup_store_1.InMemoryDedupStore();
    const published = [];
    const outbound = new outbound_mqtt_adapter_1.OutboundMqttAdapter(async (_topic, payload) => {
        published.push(payload);
    }, "locker/test/response", () => "2026-04-11T10:00:00Z");
    const openCompartment = new open_compartment_1.OpenCompartmentUseCase(bus, configStub, new scheduler_1.RunAfterCompleteScheduler());
    const pollSnapshot = new state_publishing_1.PollCompartmentStateUseCase(bus, configStub, outbound, "locker/test/state/compartments");
    const dispatcher = new command_dispatcher_1.CommandDispatcher(new inbound_protocol_guard_1.InboundProtocolGuard(dedup), outbound);
    dispatcher.register((0, open_compartment_handler_1.createOpenCompartmentHandler)({
        openCompartment,
        outbound,
        dedup,
        pollSnapshot,
    }));
    return {
        bus,
        dedup,
        dispatcher,
        openCompartment,
        published,
    };
}
(0, node_test_1.test)("dispatcher executes valid open_compartment once", async () => {
    const { bus, dispatcher, openCompartment, published } = createDispatcherHarness();
    await dispatcher.dispatch("locker/test/command", JSON.stringify({
        action: "open_compartment",
        transaction_id: "txn-1",
        message_id: "msg-1",
        timestamp: "2026-04-11T10:00:00Z",
        data: { compartment_number: 1 },
    }));
    openCompartment.stopAllMonitoring();
    strict_1.default.equal(bus.flashCalls.length, 1);
    const response = published
        .map((payload) => JSON.parse(payload))
        .find((message) => message.type === "command_response");
    strict_1.default.equal(response?.result, "success");
});
(0, node_test_1.test)("dispatcher ignores duplicate message_id before side effects", async () => {
    const { bus, dispatcher, openCompartment, published } = createDispatcherHarness();
    const command = {
        action: "open_compartment",
        transaction_id: "txn-2",
        message_id: "msg-dup",
        timestamp: "2026-04-11T10:00:00Z",
        data: { compartment_number: 1 },
    };
    await dispatcher.dispatch("locker/test/command", JSON.stringify(command));
    await dispatcher.dispatch("locker/test/command", JSON.stringify({ ...command, data: { compartment_number: 7 } }));
    openCompartment.stopAllMonitoring();
    strict_1.default.equal(bus.flashCalls.length, 1);
    strict_1.default.equal(published.filter((payload) => JSON.parse(payload).type === "command_response").length, 1);
});
(0, node_test_1.test)("dispatcher rejects invalid payload with structured error", async () => {
    const { bus, dispatcher, openCompartment, published } = createDispatcherHarness();
    await dispatcher.dispatch("locker/test/command", JSON.stringify({
        action: "open_compartment",
        transaction_id: "txn-invalid",
        message_id: "msg-invalid",
        timestamp: "2026-04-11T10:00:00Z",
        data: { compartment_number: 0 },
    }));
    openCompartment.stopAllMonitoring();
    strict_1.default.equal(bus.flashCalls.length, 0);
    strict_1.default.equal(published.length, 1);
    const response = JSON.parse(published[0]);
    strict_1.default.equal(response.result, "error");
    strict_1.default.equal(response.error_code, "INVALID_COMMAND");
});
(0, node_test_1.test)("dispatcher rejects missing transaction_id without side effects", async () => {
    const { bus, dispatcher, openCompartment, published } = createDispatcherHarness();
    await dispatcher.dispatch("locker/test/command", JSON.stringify({
        action: "open_compartment",
        transaction_id: "   ",
        message_id: "msg-5",
        timestamp: "2026-04-11T10:00:00Z",
        data: { compartment_number: 5 },
    }));
    openCompartment.stopAllMonitoring();
    strict_1.default.equal(bus.flashCalls.length, 0);
    strict_1.default.equal(published.length, 0);
});
