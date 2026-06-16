"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const reconnect_coordinator_1 = require("../../src/adapters/modbus/reconnect-coordinator");
(0, node_test_1.test)('ReconnectCoordinator retries until connect succeeds', async () => {
    const coordinator = new reconnect_coordinator_1.ReconnectCoordinator({
        maxAttempts: 0,
        delayMs: 10,
    });
    let attempts = 0;
    await coordinator.run(async () => {
        attempts++;
        if (attempts < 2) {
            throw new Error('connect failed');
        }
    });
    strict_1.default.equal(attempts, 2);
    strict_1.default.equal(coordinator.getAttempts(), 0);
});
(0, node_test_1.test)('ReconnectCoordinator respects maxAttempts', async () => {
    const coordinator = new reconnect_coordinator_1.ReconnectCoordinator({
        maxAttempts: 2,
        delayMs: 10,
    });
    let attempts = 0;
    await strict_1.default.rejects(() => coordinator.run(async () => {
        attempts++;
        throw new Error('connect failed');
    }), /connect failed/);
    strict_1.default.equal(attempts, 2);
});
(0, node_test_1.test)('ReconnectCoordinator deduplicates concurrent reconnect calls', async () => {
    const coordinator = new reconnect_coordinator_1.ReconnectCoordinator({ delayMs: 10 });
    let attempts = 0;
    await Promise.all([
        coordinator.run(async () => {
            attempts++;
            await delay(20);
        }),
        coordinator.run(async () => {
            attempts++;
            await delay(20);
        }),
    ]);
    strict_1.default.equal(attempts, 1);
});
function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}
