"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.FakeLockerBus = void 0;
class FakeLockerBus {
    flashCalls = [];
    writeCoilCalls = [];
    turnAllOffCalls = [];
    relayStates = new Map();
    doorStates = new Map();
    connected = true;
    slaveIds;
    constructor(slaveIds = [1]) {
        this.slaveIds = slaveIds;
    }
    async connect() {
        this.connected = true;
    }
    async disconnect() {
        this.connected = false;
    }
    getConnectionState() {
        return this.connected ? "connected" : "disconnected";
    }
    async ensureConnected() {
        return this.connected;
    }
    async flashRelay(target, durationMs) {
        this.flashCalls.push({ target, durationMs });
        this.relayStates.set(this.key(target), true);
    }
    recordWriteCoil(slaveId, address, value) {
        this.writeCoilCalls.push({ slaveId, address, value });
    }
    async readRelayState(target) {
        return this.relayStates.get(this.key(target)) ?? false;
    }
    async readDoorSensor(target) {
        return this.doorStates.get(this.key(target)) ?? "closed";
    }
    async turnAllRelaysOff(slaveId) {
        this.turnAllOffCalls.push(slaveId);
    }
    getConfiguredSlaveIds() {
        return [...this.slaveIds];
    }
    reloadRuntimeConfig = async () => undefined;
    setRelayState(target, on) {
        this.relayStates.set(this.key(target), on);
    }
    key(target) {
        return `${target.slaveId}:${target.relayAddress}`;
    }
}
exports.FakeLockerBus = FakeLockerBus;
