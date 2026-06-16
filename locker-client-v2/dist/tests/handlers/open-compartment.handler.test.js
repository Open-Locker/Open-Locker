"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const open_compartment_handler_1 = require("../../src/adapters/mqtt/handlers/open-compartment.handler");
const fake_locker_bus_1 = require("../helpers/fake-locker-bus");
const outbound_mqtt_adapter_1 = require("../../src/adapters/mqtt/outbound-mqtt.adapter");
const dedup_store_1 = require("../../src/adapters/mqtt/dedup-store");
const open_compartment_1 = require("../../src/application/open-compartment");
const state_publishing_1 = require("../../src/application/state-publishing");
const scheduler_1 = require("../../src/infrastructure/scheduler");
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
(0, node_test_1.test)("open compartment handler responds success and preserves transaction_id", async () => {
    const bus = new fake_locker_bus_1.FakeLockerBus([1]);
    const published = [];
    const outbound = new outbound_mqtt_adapter_1.OutboundMqttAdapter(async (_topic, payload) => {
        published.push(payload);
    }, "locker/test/response", () => "2026-06-16T12:00:00.000Z");
    const openCompartment = new open_compartment_1.OpenCompartmentUseCase(bus, configStub, new scheduler_1.RunAfterCompleteScheduler());
    const pollSnapshot = new state_publishing_1.PollCompartmentStateUseCase(bus, configStub, outbound, "locker/test/state/compartments");
    const dedup = new dedup_store_1.InMemoryDedupStore();
    const handler = (0, open_compartment_handler_1.createOpenCompartmentHandler)({
        openCompartment,
        outbound,
        dedup,
        pollSnapshot,
    });
    await handler.handle({ lockerUuid: "test" }, {
        action: "open_compartment",
        message_id: "msg-1",
        transaction_id: "tx-abc",
        timestamp: "2026-06-16T12:00:00.000Z",
        data: { compartment_number: 1 },
    });
    openCompartment.stopAllMonitoring();
    strict_1.default.equal(bus.flashCalls.length, 1);
    const responsePayload = published
        .map((payload) => JSON.parse(payload))
        .find((message) => message.type === "command_response");
    strict_1.default.ok(responsePayload);
    const response = responsePayload;
    strict_1.default.equal(response.result, "success");
    strict_1.default.equal(response.transaction_id, "tx-abc");
    strict_1.default.equal(typeof response.message_id, "string");
    strict_1.default.equal(response.timestamp, "2026-06-16T12:00:00.000Z");
});
(0, node_test_1.test)("duplicate completed transaction triggers only one flash", async () => {
    const bus = new fake_locker_bus_1.FakeLockerBus([1]);
    const outbound = new outbound_mqtt_adapter_1.OutboundMqttAdapter(async () => undefined, "locker/test/response");
    const dedup = new dedup_store_1.InMemoryDedupStore();
    dedup.markCommandCompleted("tx-dup", "open_compartment");
    const handler = (0, open_compartment_handler_1.createOpenCompartmentHandler)({
        openCompartment: new open_compartment_1.OpenCompartmentUseCase(bus, configStub, new scheduler_1.RunAfterCompleteScheduler()),
        outbound,
        dedup,
        pollSnapshot: new state_publishing_1.PollCompartmentStateUseCase(bus, configStub, outbound, "locker/test/state/compartments"),
    });
    await handler.handle({ lockerUuid: "test" }, {
        action: "open_compartment",
        message_id: "msg-2",
        transaction_id: "tx-dup",
        timestamp: "2026-06-16T12:00:00.000Z",
        data: { compartment_number: 1 },
    });
    strict_1.default.equal(bus.flashCalls.length, 0);
});
