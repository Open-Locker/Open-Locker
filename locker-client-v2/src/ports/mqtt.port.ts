export interface OutboundPublishOptions {
  qos?: 0 | 1 | 2;
  retain?: boolean;
}

export interface CommandResponseBody {
  type: 'command_response';
  action: string;
  result: 'success' | 'error';
  transaction_id: string;
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

export interface DedupStorePort {
  hasSeenMessageId(messageId: string): boolean;
  rememberMessageId(messageId: string): void;
  getCommandRecord(
    transactionId: string,
  ): { action: string; status: 'in_progress' | 'completed' } | null;
  markCommandInProgress(transactionId: string, action: string): void;
  markCommandCompleted(transactionId: string, action: string): void;
}
