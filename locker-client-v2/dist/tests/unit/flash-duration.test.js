"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const compartment_1 = require("../../src/domain/compartment");
(0, node_test_1.test)('normalizeFlashDurationMs accepts default range', () => {
    strict_1.default.equal((0, compartment_1.normalizeFlashDurationMs)(200), 200);
    strict_1.default.equal((0, compartment_1.normalizeFlashDurationMs)(undefined), 200);
});
(0, node_test_1.test)('normalizeFlashDurationMs rejects below minimum', () => {
    strict_1.default.throws(() => (0, compartment_1.normalizeFlashDurationMs)(50), /at least 100ms/);
});
(0, node_test_1.test)('normalizeFlashDurationMs rejects above maximum', () => {
    strict_1.default.throws(() => (0, compartment_1.normalizeFlashDurationMs)(9999), /must not exceed 500ms/);
});
(0, node_test_1.test)('toFlashDurationSteps rounds up to 100ms units', () => {
    strict_1.default.equal((0, compartment_1.toFlashDurationSteps)(200), 2);
    strict_1.default.equal((0, compartment_1.toFlashDurationSteps)(150), 2);
    strict_1.default.equal(compartment_1.MIN_FLASH_DURATION_MS, 100);
    strict_1.default.equal(compartment_1.MAX_FLASH_DURATION_MS, 500);
});
