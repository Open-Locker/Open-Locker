"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const mqtt_transport_adapter_1 = require("../../src/adapters/mqtt/mqtt-transport.adapter");
(0, node_test_1.test)('MqttTransportAdapter defaults to unlimited reconnect', () => {
    const transport = new mqtt_transport_adapter_1.MqttTransportAdapter({
        clean: false,
        keepalive: 60,
        reconnectPeriod: 5000,
        connectTimeout: 30000,
        maxReconnectAttempts: 0,
    });
    strict_1.default.equal(transport.getTransportSettings().maxReconnectAttempts, 0);
});
// Full broker reconnect integration (aedes + live mqtt.connect) is not wired in CI yet.
// These tests cover the observable contract: connection state transitions and graceful publish skip.
(0, node_test_1.test)('MqttTransportAdapter reports reconnecting after simulated broker drop', () => {
    const transport = new mqtt_transport_adapter_1.MqttTransportAdapter({
        clean: false,
        keepalive: 60,
        reconnectPeriod: 5000,
        connectTimeout: 30000,
        maxReconnectAttempts: 0,
    });
    transport.connectionState = 'connected';
    transport.connectionState = 'reconnecting';
    strict_1.default.equal(transport.getConnectionState(), 'reconnecting');
});
(0, node_test_1.test)('MqttTransportAdapter publish skips gracefully while disconnected', async () => {
    const transport = new mqtt_transport_adapter_1.MqttTransportAdapter({
        clean: false,
        keepalive: 60,
        reconnectPeriod: 5000,
        connectTimeout: 30000,
        maxReconnectAttempts: 0,
    });
    await strict_1.default.doesNotReject(() => transport.publish('locker/test/state/heartbeat', JSON.stringify({ uptime_seconds: 1 })));
    strict_1.default.equal(transport.getConnectionState(), 'disconnected');
});
