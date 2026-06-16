"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.CONFIG_FILE = exports.RUNTIME_CONFIG_OVERLAY_FILE = exports.MQTT_DEDUP_STATE_FILE = exports.PROVISIONING_STATE_FILE = exports.MQTT_CREDENTIALS_FILE = exports.MQTT_CLIENT_ID_FILE = exports.CONFIG_DIR = exports.DATA_DIR = void 0;
const path_1 = __importDefault(require("path"));
exports.DATA_DIR = process.env.DATA_DIR || "/data";
exports.CONFIG_DIR = process.env.CONFIG_DIR || "/config";
exports.MQTT_CLIENT_ID_FILE = path_1.default.join(exports.DATA_DIR, ".mqtt-client-id");
exports.MQTT_CREDENTIALS_FILE = path_1.default.join(exports.DATA_DIR, ".mqtt-credentials.json");
exports.PROVISIONING_STATE_FILE = path_1.default.join(exports.DATA_DIR, ".provisioning-state");
exports.MQTT_DEDUP_STATE_FILE = path_1.default.join(exports.DATA_DIR, ".mqtt-dedup-state.json");
exports.RUNTIME_CONFIG_OVERLAY_FILE = path_1.default.join(exports.DATA_DIR, ".runtime-config-overlay.json");
exports.CONFIG_FILE = path_1.default.join(exports.CONFIG_DIR, "locker-config.yml");
