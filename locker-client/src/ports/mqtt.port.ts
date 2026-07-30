export interface OutboundPublishOptions {
  qos?: 0 | 1 | 2;
  retain?: boolean;
}

export interface CommandResponseBody {
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
  /**
   * Resolves true when the payload was handed to the broker, false when it was
   * dropped because the transport is not connected. Callers that need delivery
   * to be observable have to read this — a dropped publish is not an error the
   * transport can raise without turning every fire-and-forget publish into an
   * unhandled rejection.
   */
  publish(topic: string, payload: string, options?: OutboundPublishOptions): Promise<boolean>;
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
  /**
   * Retains what was answered for a transaction so a redelivery can be given
   * the same answer instead of silence. A backend usually retries because it
   * never saw the first response, so replaying it is what unblocks it.
   */
  rememberCommandResponse(transactionId: string, response: CommandResponseBody): void;
  getCommandResponse(transactionId: string): CommandResponseBody | null;
}
