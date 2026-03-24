import fs from "fs";
import { MQTT_DEDUP_STATE_FILE } from "../config/paths";
import { logger } from "../helper/logger";

type CommandStatus = "in_progress" | "completed";

interface CommandRecord {
  action: string;
  status: CommandStatus;
  updatedAt: string;
}

interface DedupState {
  seenMessageIds: Record<string, string>;
  commandRecords: Record<string, CommandRecord>;
}

class MQTTDedupService {
  private readonly MESSAGE_ID_TTL_MS = 24 * 60 * 60 * 1000;
  private readonly COMMAND_RECORD_TTL_MS = 7 * 24 * 60 * 60 * 1000;
  private readonly MAX_SEEN_MESSAGE_IDS = 10000;
  private readonly MAX_COMMAND_RECORDS = 5000;
  private state: DedupState | null = null;

  hasSeenMessageId(messageId: string): boolean {
    const state = this.loadState();
    this.pruneExpiredEntries(state);

    return messageId in state.seenMessageIds;
  }

  rememberMessageId(messageId: string): void {
    const state = this.loadState();
    this.pruneExpiredEntries(state);
    state.seenMessageIds[messageId] = new Date().toISOString();
    this.saveState(state);
  }

  getCommandRecord(transactionId: string): CommandRecord | null {
    const state = this.loadState();
    this.pruneExpiredEntries(state);

    return state.commandRecords[transactionId] || null;
  }

  markCommandInProgress(transactionId: string, action: string): void {
    const state = this.loadState();
    this.pruneExpiredEntries(state);
    state.commandRecords[transactionId] = {
      action,
      status: "in_progress",
      updatedAt: new Date().toISOString(),
    };
    this.saveState(state);
  }

  markCommandCompleted(transactionId: string, action: string): void {
    const state = this.loadState();
    this.pruneExpiredEntries(state);
    state.commandRecords[transactionId] = {
      action,
      status: "completed",
      updatedAt: new Date().toISOString(),
    };
    this.saveState(state);
  }

  private loadState(): DedupState {
    if (this.state) {
      return this.state;
    }

    const emptyState: DedupState = {
      seenMessageIds: {},
      commandRecords: {},
    };

    try {
      if (!fs.existsSync(MQTT_DEDUP_STATE_FILE)) {
        this.state = emptyState;
        return this.state;
      }

      const rawState = fs.readFileSync(MQTT_DEDUP_STATE_FILE, "utf8").trim();
      if (!rawState) {
        this.state = emptyState;
        return this.state;
      }

      const parsedState = JSON.parse(rawState) as Partial<DedupState>;
      this.state = {
        seenMessageIds: parsedState.seenMessageIds || {},
        commandRecords: parsedState.commandRecords || {},
      };

      return this.state;
    } catch (error) {
      logger.warn("Failed to load MQTT dedup state, starting fresh:", error);
      this.state = emptyState;
      return this.state;
    }
  }

  private saveState(state: DedupState): void {
    this.state = state;

    try {
      fs.writeFileSync(
        MQTT_DEDUP_STATE_FILE,
        JSON.stringify(state, null, 2),
        "utf8",
      );
    } catch (error) {
      logger.error("Failed to persist MQTT dedup state:", error);
      throw error;
    }
  }

  private pruneExpiredEntries(state: DedupState): void {
    const now = Date.now();
    let changed = false;

    for (const [messageId, timestamp] of Object.entries(state.seenMessageIds)) {
      if (this.isExpired(timestamp, this.MESSAGE_ID_TTL_MS, now)) {
        delete state.seenMessageIds[messageId];
        changed = true;
      }
    }

    for (const [transactionId, record] of Object.entries(state.commandRecords)) {
      if (this.isExpired(record.updatedAt, this.COMMAND_RECORD_TTL_MS, now)) {
        delete state.commandRecords[transactionId];
        changed = true;
      }
    }

    if (this.enforceMaxEntries(state.seenMessageIds, this.MAX_SEEN_MESSAGE_IDS)) {
      changed = true;
    }

    if (this.enforceMaxEntries(state.commandRecords, this.MAX_COMMAND_RECORDS)) {
      changed = true;
    }

    if (changed) {
      this.saveState(state);
    }
  }

  private isExpired(timestamp: string, ttlMs: number, now: number): boolean {
    const parsedTimestamp = Date.parse(timestamp);

    if (Number.isNaN(parsedTimestamp)) {
      return true;
    }

    return now - parsedTimestamp > ttlMs;
  }

  private enforceMaxEntries<T extends { updatedAt?: string } | string>(
    entries: Record<string, T>,
    maxEntries: number,
  ): boolean {
    const keys = Object.keys(entries);

    if (keys.length <= maxEntries) {
      return false;
    }

    const sortedKeys = keys.sort((leftKey, rightKey) => {
      const leftTimestamp = this.resolveTimestamp(entries[leftKey]);
      const rightTimestamp = this.resolveTimestamp(entries[rightKey]);

      return leftTimestamp - rightTimestamp;
    });

    const keysToDelete = sortedKeys.slice(0, keys.length - maxEntries);
    for (const key of keysToDelete) {
      delete entries[key];
    }

    return keysToDelete.length > 0;
  }

  private resolveTimestamp(entry: { updatedAt?: string } | string): number {
    const rawTimestamp =
      typeof entry === "string" ? entry : (entry.updatedAt ?? "");
    const parsedTimestamp = Date.parse(rawTimestamp);

    return Number.isNaN(parsedTimestamp) ? 0 : parsedTimestamp;
  }
}

export const mqttDedupService = new MQTTDedupService();
