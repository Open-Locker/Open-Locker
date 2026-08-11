import fs from 'fs';
import { z } from 'zod';
import type { CommandRecord, DedupStorePort, StoredCommandResponse } from '../../ports/mqtt.port';
import { MQTT_DEDUP_STATE_FILE } from '../../infrastructure/paths';
import {
  atomicWriteFileSync,
  PersistentStateCorruptedError,
} from '../../infrastructure/file-persistence';

interface DedupState {
  seenMessageIds: Record<string, string>;
  commandRecords: Record<string, CommandRecord>;
}

interface PersistedDedupState extends DedupState {
  version: 2;
}

interface DedupStoreOptions {
  now?: () => Date;
  seenMessageIdTtlMs?: number;
  maxSeenMessageIds?: number;
  deliveredCommandRetentionMs?: number;
}

export const DEFAULT_SEEN_MESSAGE_ID_TTL_MS = 30 * 24 * 60 * 60 * 1000;
export const DEFAULT_MAX_SEEN_MESSAGE_IDS = 10_000;
export const DEFAULT_DELIVERED_COMMAND_RETENTION_MS = 30 * 24 * 60 * 60 * 1000;

const timestampSchema = z.string().refine((value) => Number.isFinite(Date.parse(value)));
const storedResponseSchema = z
  .object({
    result: z.enum(['success', 'error']),
    message: z.string().optional(),
    error_code: z.string().optional(),
    applied_config_hash: z.string().optional(),
  })
  .strict();
const legacyResponseSchema = storedResponseSchema.extend({
  action: z.string(),
  transaction_id: z.string(),
});
const inProgressCommandRecordSchema = z
  .object({
    action: z.string(),
    status: z.literal('in_progress'),
    updatedAt: timestampSchema,
  })
  .strict();
const completedCommandRecordSchema = z
  .object({
    action: z.string(),
    status: z.literal('completed'),
    updatedAt: timestampSchema,
    response: storedResponseSchema,
    responseDeliveredAt: timestampSchema.optional(),
  })
  .strict();
const legacyCompletedCommandRecordSchema = z
  .object({
    action: z.string(),
    status: z.literal('completed'),
    updatedAt: timestampSchema,
    legacyResponseUnavailable: z.literal(true),
  })
  .strict();
const commandRecordSchema = z.union([
  inProgressCommandRecordSchema,
  completedCommandRecordSchema,
  legacyCompletedCommandRecordSchema,
]);
const persistedDedupStateSchema = z
  .object({
    version: z.literal(2),
    seenMessageIds: z.record(z.string(), timestampSchema).default({}),
    commandRecords: z.record(z.string(), commandRecordSchema).default({}),
  })
  .strict();
const unversionedCommandRecordSchema = z.union([
  inProgressCommandRecordSchema,
  z
    .object({
      action: z.string(),
      status: z.literal('completed'),
      updatedAt: timestampSchema,
      response: legacyResponseSchema,
      responseDeliveredAt: timestampSchema.optional(),
    })
    .strict(),
  z
    .object({
      action: z.string(),
      status: z.literal('completed'),
      updatedAt: timestampSchema,
    })
    .strict(),
]);
const unversionedDedupStateSchema = z
  .object({
    seenMessageIds: z.record(z.string(), timestampSchema).default({}),
    commandRecords: z.record(z.string(), unversionedCommandRecordSchema).default({}),
  })
  .strict();

export class FileDedupStore implements DedupStorePort {
  private state: DedupState | null = null;
  private readonly now: () => Date;
  private readonly seenMessageIdTtlMs: number;
  private readonly maxSeenMessageIds: number;
  private readonly deliveredCommandRetentionMs: number;

  constructor(
    private readonly filePath: string = MQTT_DEDUP_STATE_FILE,
    options: DedupStoreOptions = {},
  ) {
    this.now = options.now ?? (() => new Date());
    this.seenMessageIdTtlMs = options.seenMessageIdTtlMs ?? DEFAULT_SEEN_MESSAGE_ID_TTL_MS;
    this.maxSeenMessageIds = options.maxSeenMessageIds ?? DEFAULT_MAX_SEEN_MESSAGE_IDS;
    this.deliveredCommandRetentionMs =
      options.deliveredCommandRetentionMs ?? DEFAULT_DELIVERED_COMMAND_RETENTION_MS;
  }

  assertHealthy(): void {
    this.loadState();
  }

  hasSeenMessageId(messageId: string): boolean {
    const state = this.loadState();
    return messageId in state.seenMessageIds;
  }

  rememberMessageId(messageId: string): void {
    const state = this.loadState();
    this.saveState({
      ...state,
      seenMessageIds: {
        ...state.seenMessageIds,
        [messageId]: this.now().toISOString(),
      },
    });
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
    this.saveState({
      ...state,
      commandRecords: {
        ...state.commandRecords,
        [transactionId]: {
          action,
          status: 'in_progress',
          updatedAt: this.now().toISOString(),
        },
      },
    });
  }

  markCommandCompleted(
    transactionId: string,
    action: string,
    response: StoredCommandResponse,
  ): void {
    const state = this.loadState();
    this.saveState({
      ...state,
      commandRecords: {
        ...state.commandRecords,
        [transactionId]: {
          action,
          status: 'completed',
          updatedAt: this.now().toISOString(),
          response,
        },
      },
    });
  }

  markCommandResponsePending(transactionId: string): void {
    const state = this.loadState();
    const record = state.commandRecords[transactionId];
    if (
      !record ||
      record.status !== 'completed' ||
      !record.response ||
      !record.responseDeliveredAt
    ) {
      return;
    }
    const pendingRecord = { ...record };
    delete pendingRecord.responseDeliveredAt;
    this.saveState({
      ...state,
      commandRecords: {
        ...state.commandRecords,
        [transactionId]: {
          ...pendingRecord,
          updatedAt: this.now().toISOString(),
        },
      },
    });
  }

  markCommandResponseDelivered(transactionId: string): void {
    const state = this.loadState();
    const record = state.commandRecords[transactionId];
    if (!record || record.status !== 'completed' || !record.response) {
      return;
    }
    const deliveredAt = this.now().toISOString();
    this.saveState({
      ...state,
      commandRecords: {
        ...state.commandRecords,
        [transactionId]: {
          ...record,
          updatedAt: deliveredAt,
          responseDeliveredAt: deliveredAt,
        },
      },
    });
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

    let loaded: DedupState;
    let migratedLegacy = false;
    try {
      const raw: unknown = JSON.parse(fs.readFileSync(this.filePath, 'utf8'));
      if (hasVersionMarker(raw)) {
        const parsed = persistedDedupStateSchema.parse(raw);
        loaded = {
          seenMessageIds: parsed.seenMessageIds,
          commandRecords: parsed.commandRecords,
        };
      } else {
        loaded = migrateUnversionedState(unversionedDedupStateSchema.parse(raw));
        migratedLegacy = true;
      }
    } catch (error) {
      throw new PersistentStateCorruptedError(
        'MQTT deduplication and response state',
        this.filePath,
        { cause: error },
      );
    }
    const pruned = this.pruneState(loaded);
    if (migratedLegacy || JSON.stringify(pruned) !== JSON.stringify(loaded)) {
      this.writeState(pruned);
    }
    this.state = pruned;
    return pruned;
  }

  private saveState(state: DedupState): void {
    const pruned = this.pruneState(state);
    this.writeState(pruned);
    this.state = pruned;
  }

  private writeState(state: DedupState): void {
    const persisted: PersistedDedupState = { version: 2, ...state };
    atomicWriteFileSync(this.filePath, JSON.stringify(persisted, null, 2), { mode: 0o600 });
  }

  private pruneState(state: DedupState): DedupState {
    const now = this.now().getTime();
    const seenCutoff = now - this.seenMessageIdTtlMs;
    const retainedMessageIds = Object.entries(state.seenMessageIds)
      .filter(([, timestamp]) => Date.parse(timestamp) >= seenCutoff)
      .toSorted(
        ([idA, timestampA], [idB, timestampB]) =>
          Date.parse(timestampA) - Date.parse(timestampB) || idA.localeCompare(idB),
      )
      .slice(-this.maxSeenMessageIds);
    const commandCutoff = now - this.deliveredCommandRetentionMs;
    const retainedCommandRecords = Object.entries(state.commandRecords).filter(([, record]) => {
      if (record.status !== 'completed' || !record.response || !record.responseDeliveredAt) {
        return true;
      }
      return Date.parse(record.responseDeliveredAt) >= commandCutoff;
    });

    return {
      seenMessageIds: Object.fromEntries(retainedMessageIds),
      commandRecords: Object.fromEntries(retainedCommandRecords),
    };
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

  markCommandCompleted(
    transactionId: string,
    action: string,
    response: StoredCommandResponse,
  ): void {
    this.commandRecords.set(transactionId, {
      action,
      status: 'completed',
      updatedAt: new Date().toISOString(),
      response,
    });
  }

  markCommandResponsePending(transactionId: string): void {
    const record = this.commandRecords.get(transactionId);
    if (
      !record ||
      record.status !== 'completed' ||
      !record.response ||
      !record.responseDeliveredAt
    ) {
      return;
    }
    const pendingRecord = { ...record };
    delete pendingRecord.responseDeliveredAt;
    this.commandRecords.set(transactionId, {
      ...pendingRecord,
      updatedAt: new Date().toISOString(),
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

function hasVersionMarker(value: unknown): boolean {
  return typeof value === 'object' && value !== null && 'version' in value;
}

function migrateUnversionedState(state: z.infer<typeof unversionedDedupStateSchema>): DedupState {
  const commandRecords = Object.fromEntries(
    Object.entries(state.commandRecords).map(([transactionId, record]) => {
      if (record.status === 'in_progress') {
        return [transactionId, record];
      }
      if (!('response' in record)) {
        return [
          transactionId,
          {
            ...record,
            legacyResponseUnavailable: true as const,
          },
        ];
      }
      if (
        record.response.transaction_id !== transactionId ||
        record.response.action !== record.action
      ) {
        throw new Error('Legacy command response identity does not match its command record');
      }
      const { action: _action, transaction_id: _transactionId, ...response } = record.response;
      return [
        transactionId,
        {
          ...record,
          response,
        },
      ];
    }),
  );

  return {
    seenMessageIds: state.seenMessageIds,
    commandRecords,
  };
}
