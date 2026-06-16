"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ModbusBusActor = void 0;
const p_queue_1 = __importDefault(require("p-queue"));
const errors_1 = require("../../domain/errors");
const locker_bus_port_1 = require("../../ports/locker-bus.port");
const reconnect_coordinator_1 = require("./reconnect-coordinator");
class ModbusBusActor {
    driver;
    configuredSlaveIds;
    queue = new p_queue_1.default({ concurrency: 1 });
    connectionState = 'disconnected';
    reconnect;
    constructor(driver, reconnectOptions, configuredSlaveIds = [1]) {
        this.driver = driver;
        this.configuredSlaveIds = configuredSlaveIds;
        this.reconnect = new reconnect_coordinator_1.ReconnectCoordinator({
            maxAttempts: reconnectOptions?.maxAttempts ?? 0,
            delayMs: reconnectOptions?.delayMs ?? 5000,
        });
    }
    async connect() {
        return this.run(() => this.connectInternal(), locker_bus_port_1.BusPriority.MAINTENANCE);
    }
    async disconnect() {
        this.reconnect.cancelScheduled();
        this.queue.clear();
        await this.driver.disconnect();
        this.connectionState = 'disconnected';
    }
    getConnectionState() {
        return this.connectionState;
    }
    getConfiguredSlaveIds() {
        return [...this.configuredSlaveIds];
    }
    async ensureConnected() {
        return this.run(async () => {
            if (this.driver.isOpen()) {
                return true;
            }
            try {
                await this.reconnect.run(() => this.connectInternal());
                return this.driver.isOpen();
            }
            catch {
                return false;
            }
        }, locker_bus_port_1.BusPriority.MAINTENANCE);
    }
    async reloadRuntimeConfig() {
        return this.run(async () => {
            if (!this.driver.isOpen()) {
                await this.connectInternal();
            }
        }, locker_bus_port_1.BusPriority.MAINTENANCE);
    }
    async flashRelay(target, durationMs) {
        return this.run(() => this.driver.flashRelayOn(target.slaveId, target.relayAddress, durationMs), locker_bus_port_1.BusPriority.COMMAND);
    }
    async readRelayState(target) {
        const values = await this.run(() => this.driver.readCoils(target.slaveId, target.relayAddress, 1), locker_bus_port_1.BusPriority.POLL);
        return values[0] ?? false;
    }
    async readDoorSensor(target) {
        try {
            const values = await this.run(() => this.driver.readDiscreteInputs(target.slaveId, target.relayAddress, 1), locker_bus_port_1.BusPriority.POLL);
            return values[0] ? 'open' : 'closed';
        }
        catch {
            return 'unknown';
        }
    }
    async turnAllRelaysOff(slaveId) {
        return this.run(() => this.driver.turnAllRelaysOff(slaveId), locker_bus_port_1.BusPriority.MAINTENANCE);
    }
    getQueue() {
        return this.queue;
    }
    async connectInternal() {
        this.connectionState = 'connecting';
        await this.driver.connect();
        this.connectionState = 'connected';
        this.reconnect.resetAttempts();
    }
    run(operation, priority) {
        return this.queue.add(async () => this.runWithReconnectRetry(operation), {
            priority,
        });
    }
    async runWithReconnectRetry(operation) {
        try {
            return await operation();
        }
        catch (error) {
            if (!(0, errors_1.isReconnectableModbusError)(error)) {
                throw error;
            }
            await this.driver.disconnect();
            this.connectionState = 'disconnected';
            await this.reconnect.run(() => this.connectInternal());
            return operation();
        }
    }
}
exports.ModbusBusActor = ModbusBusActor;
