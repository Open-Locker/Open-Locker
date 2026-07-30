import fs from 'fs';
import type { CommandResponseBody, DedupStorePort } from '../../ports/mqtt.port';
import { MQTT_DEDUP_STATE_FILE } from '../../infrastructure/paths';

type CommandStatus = 'in_progress' | 'completed';

interface CommandRecord {
  action: string;
  status: CommandStatus;
  updatedAt: string;
}

interface DedupState {
  seenMessageIds: Record<string, string>;
  commandRecords: Record<string, CommandRecord>;
  commandResponses: Record<string, CommandResponseBody>;
}

export class FileDedupStore implements DedupStorePort {
  private state: DedupState | null = null;

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

  markCommandInProgress(transactionId: string, action: string): void {
    const state = this.loadState();
    state.commandRecords[transactionId] = {
      action,
      status: 'in_progress',
      updatedAt: new Date().toISOString(),
    };
    this.saveState(state);
  }

  markCommandCompleted(transactionId: string, action: string): void {
    const state = this.loadState();
    state.commandRecords[transactionId] = {
      action,
      status: 'completed',
      updatedAt: new Date().toISOString(),
    };
    this.saveState(state);
  }

  rememberCommandResponse(transactionId: string, response: CommandResponseBody): void {
    const state = this.loadState();
    state.commandResponses[transactionId] = response;
    this.saveState(state);
  }

  getCommandResponse(transactionId: string): CommandResponseBody | null {
    const state = this.loadState();
    return state.commandResponses[transactionId] ?? null;
  }

  private loadState(): DedupState {
    if (this.state) {
      return this.state;
    }

    const empty: DedupState = { seenMessageIds: {}, commandRecords: {}, commandResponses: {} };
    if (!fs.existsSync(MQTT_DEDUP_STATE_FILE)) {
      this.state = empty;
      return empty;
    }

    const parsed = JSON.parse(
      fs.readFileSync(MQTT_DEDUP_STATE_FILE, 'utf8'),
    ) as Partial<DedupState>;
    this.state = {
      seenMessageIds: parsed.seenMessageIds ?? {},
      commandRecords: parsed.commandRecords ?? {},
      // Absent in state files written before responses were retained.
      commandResponses: parsed.commandResponses ?? {},
    };
    return this.state;
  }

  private saveState(state: DedupState): void {
    this.state = state;
    fs.writeFileSync(MQTT_DEDUP_STATE_FILE, JSON.stringify(state, null, 2), 'utf8');
  }
}

export class InMemoryDedupStore implements DedupStorePort {
  private seenMessageIds = new Set<string>();
  private commandRecords = new Map<string, CommandRecord>();
  private commandResponses = new Map<string, CommandResponseBody>();

  hasSeenMessageId(messageId: string): boolean {
    return this.seenMessageIds.has(messageId);
  }

  rememberMessageId(messageId: string): void {
    this.seenMessageIds.add(messageId);
  }

  getCommandRecord(transactionId: string): CommandRecord | null {
    return this.commandRecords.get(transactionId) ?? null;
  }

  markCommandInProgress(transactionId: string, action: string): void {
    this.commandRecords.set(transactionId, {
      action,
      status: 'in_progress',
      updatedAt: new Date().toISOString(),
    });
  }

  markCommandCompleted(transactionId: string, action: string): void {
    this.commandRecords.set(transactionId, {
      action,
      status: 'completed',
      updatedAt: new Date().toISOString(),
    });
  }

  rememberCommandResponse(transactionId: string, response: CommandResponseBody): void {
    this.commandResponses.set(transactionId, response);
  }

  getCommandResponse(transactionId: string): CommandResponseBody | null {
    return this.commandResponses.get(transactionId) ?? null;
  }
}
