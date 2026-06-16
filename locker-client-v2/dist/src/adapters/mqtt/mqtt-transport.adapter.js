"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.MqttTransportAdapter = void 0;
const mqtt_1 = __importDefault(require("mqtt"));
const logging_1 = require("../../infrastructure/logging");
class MqttTransportAdapter {
    client = null;
    connectionState = 'disconnected';
    intentionalShutdown = false;
    reconnectExhausted = false;
    reconnectAttempts = 0;
    connectInFlight = null;
    messageHandler = null;
    transport;
    constructor(transport) {
        this.transport = transport;
    }
    getTransportSettings() {
        return this.transport;
    }
    getConnectionState() {
        return this.connectionState;
    }
    async connect(brokerUrl, options = {}) {
        if (this.client?.connected) {
            return;
        }
        if (this.connectInFlight) {
            return this.connectInFlight;
        }
        this.connectInFlight = this.connectInternal(brokerUrl, options).finally(() => {
            this.connectInFlight = null;
        });
        return this.connectInFlight;
    }
    async disconnect() {
        if (!this.client) {
            return;
        }
        return new Promise((resolve) => {
            this.intentionalShutdown = true;
            this.connectionState = 'disconnected';
            this.client.end(false, () => {
                this.client = null;
                this.intentionalShutdown = false;
                resolve();
            });
        });
    }
    async subscribe(topic) {
        const client = this.requireClient();
        return new Promise((resolve, reject) => {
            client.subscribe(topic, { qos: 1 }, (error) => {
                if (error) {
                    reject(error);
                    return;
                }
                resolve();
            });
        });
    }
    async publish(topic, payload, options = {}) {
        if (!this.client?.connected) {
            logging_1.logger.warn('MQTT publish skipped while disconnected', {
                topic,
                connectionState: this.connectionState,
            });
            return;
        }
        const client = this.client;
        return new Promise((resolve, reject) => {
            client.publish(topic, payload, { qos: options.qos ?? 1, retain: options.retain ?? false }, (error) => {
                if (error) {
                    reject(error);
                    return;
                }
                resolve();
            });
        });
    }
    onMessage(handler) {
        this.messageHandler = handler;
        if (this.client) {
            this.client.on('message', handler);
        }
    }
    connectInternal(brokerUrl, options) {
        this.intentionalShutdown = false;
        this.reconnectExhausted = false;
        this.connectionState = 'connecting';
        return new Promise((resolve, reject) => {
            const clientOptions = {
                keepalive: this.transport.keepalive,
                clean: this.transport.clean,
                reconnectPeriod: this.transport.reconnectPeriod,
                connectTimeout: this.transport.connectTimeout,
                ...options,
            };
            this.client = mqtt_1.default.connect(brokerUrl, clientOptions);
            let initialConnectSettled = false;
            if (this.messageHandler) {
                this.client.on('message', this.messageHandler);
            }
            this.client.on('connect', () => {
                this.reconnectAttempts = 0;
                this.connectionState = 'connected';
                initialConnectSettled = true;
                resolve();
            });
            this.client.on('error', (error) => {
                if (!initialConnectSettled) {
                    this.connectionState = 'disconnected';
                    reject(error);
                }
            });
            this.client.on('reconnect', () => {
                this.connectionState = 'reconnecting';
                this.reconnectAttempts++;
                const max = this.transport.maxReconnectAttempts;
                if (max > 0 && this.reconnectAttempts >= max) {
                    this.reconnectExhausted = true;
                    this.client?.end(true);
                }
            });
            this.client.on('close', () => {
                if (this.intentionalShutdown) {
                    this.connectionState = 'disconnected';
                    return;
                }
                if (this.reconnectExhausted) {
                    this.connectionState = 'disconnected';
                    return;
                }
                if (this.transport.reconnectPeriod === 0) {
                    this.connectionState = 'disconnected';
                    return;
                }
                this.connectionState = 'reconnecting';
            });
            this.client.on('offline', () => {
                this.connectionState = 'reconnecting';
            });
        });
    }
    requireClient() {
        if (!this.client || !this.client.connected) {
            throw new Error('MQTT client is not connected');
        }
        return this.client;
    }
}
exports.MqttTransportAdapter = MqttTransportAdapter;
