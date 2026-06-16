"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.FileRuntimeOverlayStore = void 0;
exports.sanitizeRuntimeConfigOverlay = sanitizeRuntimeConfigOverlay;
const fs_1 = __importDefault(require("fs"));
const config_normalization_1 = require("../../domain/config-normalization");
const paths_1 = require("../../infrastructure/paths");
const MAX_RELAY_ADDRESS = 7;
function sanitizeRuntimeConfigOverlay(value) {
    const overlay = value;
    if (overlay === null || typeof overlay !== 'object') {
        throw new Error('runtime config overlay must be an object');
    }
    const sanitized = {};
    if (overlay.mqtt !== undefined) {
        const mqtt = overlay.mqtt;
        if (mqtt.heartbeatInterval !== undefined &&
            Number.isInteger(mqtt.heartbeatInterval) &&
            Number(mqtt.heartbeatInterval) > 0) {
            sanitized.mqtt = { heartbeatInterval: Number(mqtt.heartbeatInterval) };
        }
    }
    if (overlay.compartments !== undefined) {
        sanitized.compartments = (0, config_normalization_1.normalizeCompartments)(overlay.compartments.map((entry) => {
            if (!Number.isInteger(entry.compartment_number) ||
                entry.compartment_number <= 0 ||
                !Number.isInteger(entry.slaveId) ||
                entry.slaveId <= 0 ||
                !Number.isInteger(entry.address) ||
                entry.address < 0 ||
                entry.address > MAX_RELAY_ADDRESS) {
                throw new Error('invalid compartment entry in overlay');
            }
            return entry;
        }));
    }
    if (overlay.appliedConfigHash !== undefined) {
        if (typeof overlay.appliedConfigHash !== 'string' ||
            !/^[a-f0-9]{64}$/i.test(overlay.appliedConfigHash)) {
            throw new Error('invalid appliedConfigHash');
        }
        sanitized.appliedConfigHash = overlay.appliedConfigHash;
    }
    if (overlay.updatedAt !== undefined) {
        sanitized.updatedAt = String(overlay.updatedAt);
    }
    return sanitized;
}
class FileRuntimeOverlayStore {
    load() {
        if (!fs_1.default.existsSync(paths_1.RUNTIME_CONFIG_OVERLAY_FILE)) {
            return null;
        }
        const raw = fs_1.default.readFileSync(paths_1.RUNTIME_CONFIG_OVERLAY_FILE, 'utf8').trim();
        if (!raw) {
            return null;
        }
        return sanitizeRuntimeConfigOverlay(JSON.parse(raw));
    }
    save(overlay) {
        const sanitized = sanitizeRuntimeConfigOverlay(overlay);
        fs_1.default.writeFileSync(paths_1.RUNTIME_CONFIG_OVERLAY_FILE, JSON.stringify(sanitized, null, 2), 'utf8');
        return sanitized;
    }
    clear() {
        if (fs_1.default.existsSync(paths_1.RUNTIME_CONFIG_OVERLAY_FILE)) {
            fs_1.default.unlinkSync(paths_1.RUNTIME_CONFIG_OVERLAY_FILE);
        }
    }
}
exports.FileRuntimeOverlayStore = FileRuntimeOverlayStore;
