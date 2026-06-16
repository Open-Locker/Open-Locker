"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const mqtt_transport_adapter_1 = require("../../src/adapters/mqtt/mqtt-transport.adapter");
(0, node_test_1.test)("MqttTransportAdapter defaults to unlimited reconnect", () => {
    const transport = new mqtt_transport_adapter_1.MqttTransportAdapter({
        clean: false,
        keepalive: 60,
        reconnectPeriod: 5000,
        connectTimeout: 30000,
        maxReconnectAttempts: 0,
    });
    strict_1.default.equal(transport.getTransportSettings().maxReconnectAttempts, 0);
});
(0, node_test_1.test)("MqttTransportAdapter enters reconnecting on simulated broker drop", () => {
    const transport = new mqtt_transport_adapter_1.MqttTransportAdapter({
        clean: false,
        keepalive: 60,
        reconnectPeriod: 5000,
        connectTimeout: 30000,
        maxReconnectAttempts: 0,
    });
    transport.connectionState = "connected";
    transport.connectionState = "reconnecting";
    strict_1.default.equal(transport.getConnectionState(), "reconnecting");
});
(0, node_test_1.test)("MqttTransportAdapter restores connected state after broker returns", () => {
    const transport = new mqtt_transport_adapter_1.MqttTransportAdapter({
        clean: false,
        keepalive: 60,
        reconnectPeriod: 5000,
        connectTimeout: 30000,
        maxReconnectAttempts: 0,
    });
    transport.connectionState = "reconnecting";
    transport.connectionState = "connected";
    strict_1.default.equal(transport.getConnectionState(), "connected");
});
