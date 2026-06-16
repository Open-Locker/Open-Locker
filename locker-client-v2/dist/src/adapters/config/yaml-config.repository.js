"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.YamlConfigRepository = void 0;
const fs_1 = __importDefault(require("fs"));
const js_yaml_1 = require("js-yaml");
const compartment_1 = require("../../domain/compartment");
const paths_1 = require("../../infrastructure/paths");
const runtime_overlay_store_1 = require("./runtime-overlay.store");
function mergeRuntimeConfig(base, overlay) {
    if (!overlay) {
        return base;
    }
    return {
        ...base,
        mqtt: {
            ...(base.mqtt ?? {}),
            heartbeatInterval: overlay.mqtt?.heartbeatInterval ?? base.mqtt?.heartbeatInterval,
        },
        compartments: overlay.compartments ?? base.compartments,
    };
}
class YamlConfigRepository {
    overlayStore;
    config = null;
    explicitRuntimeCompartments = false;
    constructor(overlayStore = new runtime_overlay_store_1.FileRuntimeOverlayStore()) {
        this.overlayStore = overlayStore;
    }
    load() {
        if (this.config) {
            return this.config;
        }
        if (!fs_1.default.existsSync(paths_1.CONFIG_FILE)) {
            throw new Error(`Configuration file not found: ${paths_1.CONFIG_FILE}`);
        }
        const parsed = (0, js_yaml_1.load)(fs_1.default.readFileSync(paths_1.CONFIG_FILE, "utf8")) ?? {};
        parsed.mqtt = parsed.mqtt ?? {};
        if (!parsed.modbus?.port) {
            throw new Error("modbus.port is required");
        }
        (0, compartment_1.normalizeFlashDurationMs)(parsed.modbus.flashDurationMs);
        const overlay = this.overlayStore.load();
        this.explicitRuntimeCompartments = overlay?.compartments !== undefined;
        this.config = mergeRuntimeConfig(parsed, overlay);
        return this.config;
    }
    reload() {
        this.config = null;
        this.explicitRuntimeCompartments = false;
        return this.load();
    }
    getCompartmentConfig(compartmentNumber) {
        const config = this.load();
        return (config.compartments?.find((c) => c.compartment_number === compartmentNumber) ?? null);
    }
    hasExplicitRuntimeCompartments() {
        this.load();
        return this.explicitRuntimeCompartments;
    }
    getFlashDurationMs() {
        return (0, compartment_1.normalizeFlashDurationMs)(this.load().modbus.flashDurationMs);
    }
    getHeartbeatIntervalSeconds() {
        return this.load().mqtt?.heartbeatInterval ?? 15;
    }
    getMqttTransportSettings() {
        const m = this.load().mqtt ?? {};
        return {
            clean: m.cleanSession ?? false,
            keepalive: m.keepaliveSeconds ?? 60,
            reconnectPeriod: m.reconnectPeriodMs ?? 5000,
            connectTimeout: m.connectTimeoutMs ?? 30000,
            maxReconnectAttempts: m.maxReconnectAttempts ?? 0,
        };
    }
    getConfiguredSlaveIds() {
        const config = this.load();
        const ids = new Set();
        for (const c of config.compartments ?? []) {
            ids.add(c.slaveId);
        }
        if (ids.size === 0) {
            ids.add(1);
        }
        return [...ids];
    }
}
exports.YamlConfigRepository = YamlConfigRepository;
