"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.InMemoryDedupStore = exports.FileDedupStore = void 0;
const fs_1 = __importDefault(require("fs"));
const paths_1 = require("../../infrastructure/paths");
class FileDedupStore {
    state = null;
    hasSeenMessageId(messageId) {
        const state = this.loadState();
        return messageId in state.seenMessageIds;
    }
    rememberMessageId(messageId) {
        const state = this.loadState();
        state.seenMessageIds[messageId] = new Date().toISOString();
        this.saveState(state);
    }
    getCommandRecord(transactionId) {
        const state = this.loadState();
        return state.commandRecords[transactionId] ?? null;
    }
    markCommandInProgress(transactionId, action) {
        const state = this.loadState();
        state.commandRecords[transactionId] = {
            action,
            status: 'in_progress',
            updatedAt: new Date().toISOString(),
        };
        this.saveState(state);
    }
    markCommandCompleted(transactionId, action) {
        const state = this.loadState();
        state.commandRecords[transactionId] = {
            action,
            status: 'completed',
            updatedAt: new Date().toISOString(),
        };
        this.saveState(state);
    }
    loadState() {
        if (this.state) {
            return this.state;
        }
        const empty = { seenMessageIds: {}, commandRecords: {} };
        if (!fs_1.default.existsSync(paths_1.MQTT_DEDUP_STATE_FILE)) {
            this.state = empty;
            return empty;
        }
        const parsed = JSON.parse(fs_1.default.readFileSync(paths_1.MQTT_DEDUP_STATE_FILE, 'utf8'));
        this.state = {
            seenMessageIds: parsed.seenMessageIds ?? {},
            commandRecords: parsed.commandRecords ?? {},
        };
        return this.state;
    }
    saveState(state) {
        this.state = state;
        fs_1.default.writeFileSync(paths_1.MQTT_DEDUP_STATE_FILE, JSON.stringify(state, null, 2), 'utf8');
    }
}
exports.FileDedupStore = FileDedupStore;
class InMemoryDedupStore {
    seenMessageIds = new Set();
    commandRecords = new Map();
    hasSeenMessageId(messageId) {
        return this.seenMessageIds.has(messageId);
    }
    rememberMessageId(messageId) {
        this.seenMessageIds.add(messageId);
    }
    getCommandRecord(transactionId) {
        return this.commandRecords.get(transactionId) ?? null;
    }
    markCommandInProgress(transactionId, action) {
        this.commandRecords.set(transactionId, {
            action,
            status: 'in_progress',
            updatedAt: new Date().toISOString(),
        });
    }
    markCommandCompleted(transactionId, action) {
        this.commandRecords.set(transactionId, {
            action,
            status: 'completed',
            updatedAt: new Date().toISOString(),
        });
    }
}
exports.InMemoryDedupStore = InMemoryDedupStore;
