"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const bus_actor_1 = require("../../src/adapters/modbus/bus-actor");
const locker_bus_port_1 = require("../../src/ports/locker-bus.port");
class FakeModbusDriver {
    operations = [];
    open = false;
    async connect() {
        this.operations.push('connect');
        this.open = true;
    }
    async disconnect() {
        this.operations.push('disconnect');
        this.open = false;
    }
    isOpen() {
        return this.open;
    }
    async flashRelayOn(slaveId, address, durationMs) {
        this.operations.push(`flash:${slaveId}:${address}:${durationMs}`);
        await delay(20);
    }
    async readCoils(_slaveId, _address, _length) {
        this.operations.push('readCoils');
        await delay(20);
        return [false];
    }
    async readDiscreteInputs(_slaveId, _address, _length) {
        this.operations.push('readDiscreteInputs');
        return [true];
    }
    async turnAllRelaysOff(slaveId) {
        this.operations.push(`allOff:${slaveId}`);
    }
}
(0, node_test_1.test)('BusActor serializes concurrent operations', async () => {
    const driver = new FakeModbusDriver();
    const bus = new bus_actor_1.ModbusBusActor(driver, { maxAttempts: 0 }, [1]);
    await bus.connect();
    const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };
    const first = bus.flashRelay(target, 200);
    const second = bus.readRelayState(target);
    await Promise.all([first, second]);
    const flashIndex = driver.operations.indexOf('flash:1:0:200');
    const readIndex = driver.operations.indexOf('readCoils');
    strict_1.default.ok(flashIndex >= 0);
    strict_1.default.ok(readIndex > flashIndex);
});
(0, node_test_1.test)('BusActor command priority runs before poll reads', async () => {
    const driver = new FakeModbusDriver();
    const bus = new bus_actor_1.ModbusBusActor(driver, { maxAttempts: 0 }, [1]);
    await bus.connect();
    const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };
    const queue = bus.getQueue();
    void queue.add(async () => {
        driver.operations.push('slowPoll');
        await delay(50);
    }, { priority: locker_bus_port_1.BusPriority.POLL });
    await delay(5);
    await bus.flashRelay(target, 200);
    const slowPollIndex = driver.operations.indexOf('slowPoll');
    const flashIndex = driver.operations.findIndex((op) => op.startsWith('flash:'));
    strict_1.default.ok(slowPollIndex >= 0);
    strict_1.default.ok(flashIndex > slowPollIndex);
});
(0, node_test_1.test)('ensureConnected returns false after max reconnect attempts', async () => {
    const driver = new FailingConnectDriver();
    const bus = new bus_actor_1.ModbusBusActor(driver, { maxAttempts: 3, delayMs: 1 }, [1]);
    const result = await bus.ensureConnected();
    strict_1.default.equal(result, false);
    strict_1.default.equal(driver.connectAttempts, 3);
});
(0, node_test_1.test)('concurrent flashRelay and ensureConnected never interleave driver calls', async () => {
    const driver = new InterleavingGuardDriver();
    const bus = new bus_actor_1.ModbusBusActor(driver, { maxAttempts: 0 }, [1]);
    await bus.connect();
    const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };
    driver.markClosed();
    await Promise.all([bus.flashRelay(target, 200), bus.ensureConnected()]);
    strict_1.default.equal(driver.hadInterleavedCalls, false);
    strict_1.default.ok(driver.operations.includes('connect'));
    strict_1.default.ok(driver.operations.some((op) => op.startsWith('flash:')));
});
(0, node_test_1.test)('BusActor retries once after reconnectable transport failure', async () => {
    const driver = new ReconnectableFailureDriver();
    const bus = new bus_actor_1.ModbusBusActor(driver, { maxAttempts: 0 }, [1]);
    await bus.connect();
    const target = { compartmentNumber: 1, slaveId: 1, relayAddress: 0 };
    await bus.flashRelay(target, 200);
    strict_1.default.equal(driver.flashAttempts, 2);
    strict_1.default.ok(driver.operations.includes('disconnect'));
    strict_1.default.ok(driver.operations.filter((op) => op === 'connect').length >= 2);
});
class FailingConnectDriver {
    connectAttempts = 0;
    async connect() {
        this.connectAttempts++;
        throw new Error('connect failed');
    }
    async disconnect() { }
    isOpen() {
        return false;
    }
    async flashRelayOn() { }
    async readCoils() {
        return [false];
    }
    async readDiscreteInputs() {
        return [true];
    }
    async turnAllRelaysOff() { }
}
class InterleavingGuardDriver {
    operations = [];
    open = false;
    activeOperation = null;
    hadInterleavedCalls = false;
    async connect() {
        await this.runExclusive('connect', async () => {
            this.open = true;
        });
    }
    async disconnect() {
        await this.runExclusive('disconnect', async () => {
            this.open = false;
        });
    }
    isOpen() {
        return this.open;
    }
    markClosed() {
        this.open = false;
    }
    async flashRelayOn(slaveId, address, durationMs) {
        await this.runExclusive(`flash:${slaveId}:${address}:${durationMs}`, async () => {
            await delay(30);
        });
    }
    async readCoils() {
        return [false];
    }
    async readDiscreteInputs() {
        return [true];
    }
    async turnAllRelaysOff(slaveId) {
        this.operations.push(`allOff:${slaveId}`);
    }
    async runExclusive(label, fn) {
        if (this.activeOperation !== null) {
            this.hadInterleavedCalls = true;
        }
        this.activeOperation = label;
        this.operations.push(label);
        try {
            await fn();
        }
        finally {
            this.activeOperation = null;
        }
    }
}
class ReconnectableFailureDriver {
    operations = [];
    flashAttempts = 0;
    open = false;
    async connect() {
        this.operations.push('connect');
        this.open = true;
    }
    async disconnect() {
        this.operations.push('disconnect');
        this.open = false;
    }
    isOpen() {
        return this.open;
    }
    async flashRelayOn(slaveId, address, durationMs) {
        this.flashAttempts++;
        if (this.flashAttempts === 1) {
            throw new Error('Port Not Open');
        }
        this.operations.push(`flash:${slaveId}:${address}:${durationMs}`);
    }
    async readCoils() {
        return [false];
    }
    async readDiscreteInputs() {
        return [true];
    }
    async turnAllRelaysOff(slaveId) {
        this.operations.push(`allOff:${slaveId}`);
    }
}
function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}
