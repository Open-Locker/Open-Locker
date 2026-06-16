import type { DedupStorePort } from '../../ports/mqtt.port';
type CommandStatus = 'in_progress' | 'completed';
interface CommandRecord {
    action: string;
    status: CommandStatus;
    updatedAt: string;
}
export declare class FileDedupStore implements DedupStorePort {
    private state;
    hasSeenMessageId(messageId: string): boolean;
    rememberMessageId(messageId: string): void;
    getCommandRecord(transactionId: string): CommandRecord | null;
    markCommandInProgress(transactionId: string, action: string): void;
    markCommandCompleted(transactionId: string, action: string): void;
    private loadState;
    private saveState;
}
export declare class InMemoryDedupStore implements DedupStorePort {
    private seenMessageIds;
    private commandRecords;
    hasSeenMessageId(messageId: string): boolean;
    rememberMessageId(messageId: string): void;
    getCommandRecord(transactionId: string): CommandRecord | null;
    markCommandInProgress(transactionId: string, action: string): void;
    markCommandCompleted(transactionId: string, action: string): void;
}
export {};
