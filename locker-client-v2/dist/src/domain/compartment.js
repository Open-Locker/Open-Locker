"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.FLASH_DURATION_STEP_MS = exports.DEFAULT_FLASH_DURATION_MS = exports.MAX_FLASH_DURATION_MS = exports.MIN_FLASH_DURATION_MS = void 0;
exports.normalizeFlashDurationMs = normalizeFlashDurationMs;
exports.toFlashDurationSteps = toFlashDurationSteps;
exports.MIN_FLASH_DURATION_MS = 100;
exports.MAX_FLASH_DURATION_MS = 500;
exports.DEFAULT_FLASH_DURATION_MS = 200;
exports.FLASH_DURATION_STEP_MS = 100;
function normalizeFlashDurationMs(configured) {
    const value = configured ?? exports.DEFAULT_FLASH_DURATION_MS;
    if (!Number.isFinite(value) || value < exports.MIN_FLASH_DURATION_MS) {
        throw new Error(`flashDurationMs must be at least ${exports.MIN_FLASH_DURATION_MS}ms`);
    }
    if (value > exports.MAX_FLASH_DURATION_MS) {
        throw new Error(`flashDurationMs must not exceed ${exports.MAX_FLASH_DURATION_MS}ms`);
    }
    return value;
}
function toFlashDurationSteps(durationMs) {
    const normalized = normalizeFlashDurationMs(durationMs);
    return Math.ceil(normalized / exports.FLASH_DURATION_STEP_MS);
}
