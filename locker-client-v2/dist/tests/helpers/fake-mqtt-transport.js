"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.FakeMqttTransport = void 0;
class FakeMqttTransport {
    published = [];
    subscriptions = [];
    state = 'disconnected';
    messageHandler = null;
    transport;
    constructor(transport = {
        clean: false,
        keepalive: 60,
        reconnectPeriod: 5000,
        connectTimeout: 30000,
        maxReconnectAttempts: 0,
    }) {
        this.transport = transport;
    }
    getConnectionState() {
        return this.state;
    }
    getTransportSettings() {
        return this.transport;
    }
    async connect() {
        this.state = 'connecting';
        this.state = 'connected';
    }
    async disconnect() {
        this.state = 'disconnected';
    }
    simulateBrokerDrop() {
        this.state = 'reconnecting';
    }
    simulateBrokerRestore() {
        this.state = 'connected';
    }
    async subscribe(topic) {
        this.subscriptions.push(topic);
    }
    async publish(topic, payload, _options) {
        this.published.push({ topic, payload });
    }
    onMessage(handler) {
        this.messageHandler = handler;
    }
    emitMessage(topic, payload) {
        this.messageHandler?.(topic, Buffer.from(JSON.stringify(payload)));
    }
}
exports.FakeMqttTransport = FakeMqttTransport;
