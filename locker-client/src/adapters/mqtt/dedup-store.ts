import fs from 'fs';
import type { CommandRecord, CommandResponseBody, DedupStorePort } from '../../ports/mqtt.port';
import { MQTT_DEDUP_STATE_FILE } from '../../infrastructure/paths';

interface DedupState {
  seenMessageIds: Record<string, string>;
  commandRecords: Record<string, CommandRecord>;
}

export class FileDedupStore implements DedupStorePort {
  private state: DedupState | null = null;

  constructor(private readonly filePath: string = MQTT_DEDUP_STATE_FILE) {}

  hasSeenMessageId(messageId: string): boolean {
    const state = this.loadState();
    return messageId in state.seenMessageIds;
  }

  rememberMessageId(messageId: string): void {
    const state = this.loadState();
    state.seenMessageIds[messageId] = new Date().toISOString();
    this.saveState(state);
  }

  getCommandRecord(transactionId: string): CommandRecord | null {
    const state = this.loadState();
    return state.commandRecords[transactionId] ?? null;
  }

  listCommandRecords(): Array<{ transactionId: string; record: CommandRecord }> {
    return Object.entries(this.loadState().commandRecords).map(([transactionId, record]) => ({
      transactionId,
      record,
    }));
  }

  markCommandInProgress(transactionId: string, action: string): void {
    const state = this.loadState();
    state.commandRecords[transactionId] = {
      action,
      status: 'in_progress',
      updatedAt: new Date().toISOString(),
    };
    this.saveState(state);
  }

  markCommandCompleted(transactionId: string, action: string, response: CommandResponseBody): void {
    const state = this.loadState();
    state.commandRecords[transactionId] = {
      action,
      status: 'completed',
      updatedAt: new Date().toISOString(),
      response,
    };
    this.saveState(state);
  }

  markCommandResponseDelivered(transactionId: string): void {
    const state = this.loadState();
    const record = state.commandRecords[transactionId];
    if (!record || record.status !== 'completed' || !record.response) {
      return;
    }
    record.responseDeliveredAt = new Date().toISOString();
    record.updatedAt = record.responseDeliveredAt;
    this.saveState(state);
  }

  private loadState(): DedupState {
    if (this.state) {
      return this.state;
    }

    const empty: DedupState = { seenMessageIds: {}, commandRecords: {} };
    if (!fs.existsSync(this.filePath)) {
      this.state = empty;
      return empty;
    }

    const parsed = JSON.parse(fs.readFileSync(this.filePath, 'utf8')) as Partial<DedupState>;
    this.state = {
      seenMessageIds: parsed.seenMessageIds ?? {},
      commandRecords: parsed.commandRecords ?? {},
    };
    return this.state;
  }

  private saveState(state: DedupState): void {
    this.state = state;
    fs.writeFileSync(this.filePath, JSON.stringify(state, null, 2), 'utf8');
  }
}

export class InMemoryDedupStore implements DedupStorePort {
  private seenMessageIds = new Set<string>();
  private commandRecords = new Map<string, CommandRecord>();

  hasSeenMessageId(messageId: string): boolean {
    return this.seenMessageIds.has(messageId);
  }

  rememberMessageId(messageId: string): void {
    this.seenMessageIds.add(messageId);
  }

  getCommandRecord(transactionId: string): CommandRecord | null {
    return this.commandRecords.get(transactionId) ?? null;
  }

  listCommandRecords(): Array<{ transactionId: string; record: CommandRecord }> {
    return Array.from(this.commandRecords, ([transactionId, record]) => ({
      transactionId,
      record,
    }));
  }

  markCommandInProgress(transactionId: string, action: string): void {
    this.commandRecords.set(transactionId, {
      action,
      status: 'in_progress',
      updatedAt: new Date().toISOString(),
    });
  }

  markCommandCompleted(transactionId: string, action: string, response: CommandResponseBody): void {
    this.commandRecords.set(transactionId, {
      action,
      status: 'completed',
      updatedAt: new Date().toISOString(),
      response,
    });
  }

  markCommandResponseDelivered(transactionId: string): void {
    const record = this.commandRecords.get(transactionId);
    if (!record || record.status !== 'completed' || !record.response) {
      return;
    }
    const deliveredAt = new Date().toISOString();
    this.commandRecords.set(transactionId, {
      ...record,
      updatedAt: deliveredAt,
      responseDeliveredAt: deliveredAt,
    });
  }
}
