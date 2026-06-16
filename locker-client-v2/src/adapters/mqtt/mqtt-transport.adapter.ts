import mqtt, { MqttClient } from 'mqtt';
import type {
  MessageTransportPort,
  MqttConnectionState,
  MqttTransportSettings,
  OutboundPublishOptions,
} from '../../ports/mqtt.port';

export class MqttTransportAdapter implements MessageTransportPort {
  private client: MqttClient | null = null;
  private connectionState: MqttConnectionState = 'disconnected';
  private intentionalShutdown = false;
  private reconnectExhausted = false;
  private reconnectAttempts = 0;
  private connectInFlight: Promise<void> | null = null;
  private messageHandler: ((topic: string, payload: Buffer) => void) | null = null;
  private readonly transport: MqttTransportSettings;

  constructor(transport: MqttTransportSettings) {
    this.transport = transport;
  }

  getTransportSettings(): MqttTransportSettings {
    return this.transport;
  }

  getConnectionState(): MqttConnectionState {
    return this.connectionState;
  }

  async connect(brokerUrl: string, options: Record<string, unknown> = {}): Promise<void> {
    if (this.client?.connected) {
      return;
    }

    if (this.connectInFlight) {
      return this.connectInFlight;
    }

    this.connectInFlight = this.connectInternal(brokerUrl, options).finally(() => {
      this.connectInFlight = null;
    });

    return this.connectInFlight;
  }

  async disconnect(): Promise<void> {
    if (!this.client) {
      return;
    }

    return new Promise((resolve) => {
      this.intentionalShutdown = true;
      this.connectionState = 'disconnected';
      this.client!.end(false, () => {
        this.client = null;
        this.intentionalShutdown = false;
        resolve();
      });
    });
  }

  async subscribe(topic: string): Promise<void> {
    const client = this.requireClient();
    return new Promise((resolve, reject) => {
      client.subscribe(topic, { qos: 1 }, (error) => {
        if (error) {
          reject(error);
          return;
        }
        resolve();
      });
    });
  }

  async publish(
    topic: string,
    payload: string,
    options: OutboundPublishOptions = {},
  ): Promise<void> {
    const client = this.requireClient();
    return new Promise((resolve, reject) => {
      client.publish(
        topic,
        payload,
        { qos: options.qos ?? 1, retain: options.retain ?? false },
        (error) => {
          if (error) {
            reject(error);
            return;
          }
          resolve();
        },
      );
    });
  }

  onMessage(handler: (topic: string, payload: Buffer) => void): void {
    this.messageHandler = handler;
    if (this.client) {
      this.client.on('message', handler);
    }
  }

  private connectInternal(brokerUrl: string, options: Record<string, unknown>): Promise<void> {
    this.intentionalShutdown = false;
    this.reconnectExhausted = false;
    this.connectionState = 'connecting';

    return new Promise((resolve, reject) => {
      const clientOptions = {
        keepalive: this.transport.keepalive,
        clean: this.transport.clean,
        reconnectPeriod: this.transport.reconnectPeriod,
        connectTimeout: this.transport.connectTimeout,
        ...options,
      };

      this.client = mqtt.connect(brokerUrl, clientOptions);
      let initialConnectSettled = false;

      if (this.messageHandler) {
        this.client.on('message', this.messageHandler);
      }

      this.client.on('connect', () => {
        this.reconnectAttempts = 0;
        this.connectionState = 'connected';
        initialConnectSettled = true;
        resolve();
      });

      this.client.on('error', (error) => {
        if (!initialConnectSettled) {
          this.connectionState = 'disconnected';
          reject(error);
        }
      });

      this.client.on('reconnect', () => {
        this.connectionState = 'reconnecting';
        this.reconnectAttempts++;
        const max = this.transport.maxReconnectAttempts;
        if (max > 0 && this.reconnectAttempts >= max) {
          this.reconnectExhausted = true;
          this.client?.end(true);
        }
      });

      this.client.on('close', () => {
        if (this.intentionalShutdown) {
          this.connectionState = 'disconnected';
          return;
        }
        if (this.reconnectExhausted) {
          this.connectionState = 'disconnected';
          return;
        }
        if (this.transport.reconnectPeriod === 0) {
          this.connectionState = 'disconnected';
          return;
        }
        this.connectionState = 'reconnecting';
      });

      this.client.on('offline', () => {
        this.connectionState = 'reconnecting';
      });
    });
  }

  private requireClient(): MqttClient {
    if (!this.client || !this.client.connected) {
      throw new Error('MQTT client is not connected');
    }
    return this.client;
  }
}
