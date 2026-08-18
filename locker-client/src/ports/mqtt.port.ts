export interface OutboundPublishOptions {
  qos?: 0 | 1 | 2;
  retain?: boolean;
}

export interface CommandResponseBody {
  action: string;
  transaction_id: string;
  result: 'success' | 'error';
  message?: string;
  error_code?: string;
  applied_config_hash?: string;
}

export interface StoredCommandResponse {
  result: 'success' | 'error';
  message?: string;
  error_code?: string;
  applied_config_hash?: string;
}

export interface OutboundMqttPort {
  publishJson(
    topic: string,
    body: Record<string, unknown>,
    options?: OutboundPublishOptions,
  ): Promise<void>;
  publishCommandResponse(body: CommandResponseBody): Promise<void>;
}

export type MqttConnectionState = 'disconnected' | 'connecting' | 'connected' | 'reconnecting';

export interface MessageTransportPort {
  connect(brokerUrl: string, options: Record<string, unknown>): Promise<void>;
  disconnect(): Promise<void>;
  subscribe(topic: string): Promise<void>;
  publish(topic: string, payload: string, options?: OutboundPublishOptions): Promise<void>;
  onMessage(handler: (topic: string, payload: Buffer) => void): void;
  onConnected(handler: () => void | Promise<void>): void;
  getConnectionState(): MqttConnectionState;
  getTransportSettings(): MqttTransportSettings;
}

export interface MqttTransportSettings {
  clean: boolean;
  keepalive: number;
  reconnectPeriod: number;
  connectTimeout: number;
  maxReconnectAttempts: number;
}

interface CommandRecordBase {
  action: string;
  updatedAt: string;
}

export interface InProgressCommandRecord extends CommandRecordBase {
  status: 'in_progress';
  response?: never;
  responseDeliveredAt?: never;
  legacyResponseUnavailable?: never;
}

export interface CompletedCommandRecord extends CommandRecordBase {
  status: 'completed';
  response: StoredCommandResponse;
  responseDeliveredAt?: string;
  legacyResponseUnavailable?: never;
}

export interface LegacyCompletedCommandRecord extends CommandRecordBase {
  status: 'completed';
  legacyResponseUnavailable: true;
  response?: never;
  responseDeliveredAt?: never;
}

export type CommandRecord =
  | InProgressCommandRecord
  | CompletedCommandRecord
  | LegacyCompletedCommandRecord;

export interface DedupStorePort {
  hasSeenMessageId(messageId: string): boolean;
  rememberMessageId(messageId: string): void;
  getCommandRecord(transactionId: string): CommandRecord | null;
  listCommandRecords(): Array<{ transactionId: string; record: CommandRecord }>;
  markCommandInProgress(transactionId: string, action: string): void;
  markCommandCompleted(
    transactionId: string,
    action: string,
    response: StoredCommandResponse,
  ): void;
  markCommandResponsePending(transactionId: string): void;
  markCommandResponseDelivered(transactionId: string): void;
}
