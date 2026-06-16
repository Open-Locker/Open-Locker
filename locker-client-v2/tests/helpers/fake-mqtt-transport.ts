import type {
  MessageTransportPort,
  MqttConnectionState,
  MqttTransportSettings,
  OutboundPublishOptions,
} from "../../src/ports/mqtt.port";

export class FakeMqttTransport implements MessageTransportPort {
  readonly published: Array<{ topic: string; payload: string }> = [];
  readonly subscriptions: string[] = [];
  private state: MqttConnectionState = "disconnected";
  private messageHandler: ((topic: string, payload: Buffer) => void) | null =
    null;
  private readonly transport: MqttTransportSettings;

  constructor(
    transport: MqttTransportSettings = {
      clean: false,
      keepalive: 60,
      reconnectPeriod: 5000,
      connectTimeout: 30000,
      maxReconnectAttempts: 0,
    },
  ) {
    this.transport = transport;
  }

  getConnectionState(): MqttConnectionState {
    return this.state;
  }

  getTransportSettings(): MqttTransportSettings {
    return this.transport;
  }

  async connect(): Promise<void> {
    this.state = "connecting";
    this.state = "connected";
  }

  async disconnect(): Promise<void> {
    this.state = "disconnected";
  }

  simulateBrokerDrop(): void {
    this.state = "reconnecting";
  }

  simulateBrokerRestore(): void {
    this.state = "connected";
  }

  async subscribe(topic: string): Promise<void> {
    this.subscriptions.push(topic);
  }

  async publish(
    topic: string,
    payload: string,
    _options?: OutboundPublishOptions,
  ): Promise<void> {
    this.published.push({ topic, payload });
  }

  onMessage(handler: (topic: string, payload: Buffer) => void): void {
    this.messageHandler = handler;
  }

  emitMessage(topic: string, payload: Record<string, unknown>): void {
    this.messageHandler?.(topic, Buffer.from(JSON.stringify(payload)));
  }
}
