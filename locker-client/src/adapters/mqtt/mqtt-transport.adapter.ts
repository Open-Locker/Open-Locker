import mqtt, { MqttClient } from 'mqtt';
import type {
  MessageTransportPort,
  MqttConnectionState,
  MqttTransportSettings,
  OutboundPublishOptions,
} from '../../ports/mqtt.port';
import { logger } from '../../infrastructure/logging';

export function withVerifiedMqttTls(
  brokerUrl: string,
  options: Record<string, unknown>,
): Record<string, unknown> {
  if (!brokerUrl.toLowerCase().startsWith('mqtts://')) {
    return options;
  }

  return {
    ...options,
    rejectUnauthorized: true,
  };
}

export class MqttTransportAdapter implements MessageTransportPort {
  private client: MqttClient | null = null;
  private connectionState: MqttConnectionState = 'disconnected';
  private intentionalShutdown = false;
  private reconnectExhausted = false;
  private reconnectAttempts = 0;
  private connectInFlight: Promise<void> | null = null;
  private messageHandler: ((topic: string, payload: Buffer) => void) | null = null;
  private readonly connectedHandlers: Array<() => void | Promise<void>> = [];
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

  onConnected(handler: () => void | Promise<void>): void {
    this.connectedHandlers.push(handler);
  }

  private connectInternal(brokerUrl: string, options: Record<string, unknown>): Promise<void> {
    this.intentionalShutdown = false;
    this.reconnectExhausted = false;
    this.connectionState = 'connecting';

    const clientOptions = withVerifiedMqttTls(brokerUrl, {
      keepalive: this.transport.keepalive,
      clean: this.transport.clean,
      reconnectPeriod: this.transport.reconnectPeriod,
      connectTimeout: this.transport.connectTimeout,
      ...options,
    });

    const client = mqtt.connect(brokerUrl, clientOptions);
    this.client = client;
    if (this.messageHandler) {
      client.on('message', this.messageHandler);
    }

    this.registerConnectionLifecycle(client);
    return this.waitForInitialConnection(client, brokerUrl);
  }

  private waitForInitialConnection(client: MqttClient, brokerUrl: string): Promise<void> {
    return new Promise((resolve, reject) => {
      let settled = false;
      let connectTimeout: NodeJS.Timeout;
      const settle = (error?: Error): void => {
        if (settled) {
          return;
        }

        settled = true;
        clearTimeout(connectTimeout);
        if (error) {
          this.connectionState = 'disconnected';
          logger.error('MQTT connection failed during startup', {
            brokerUrl,
            error: error.message,
          });
          client.end(true);
          reject(error);
          return;
        }

        this.connectionState = 'connected';
        resolve();
        this.notifyConnected();
      };

      // mqtt can keep retrying without emitting an error, leaving startup
      // pending forever. Reject explicitly so the composition root can panic.
      connectTimeout = setTimeout(() => {
        settle(new Error(`MQTT connection timed out after ${this.transport.connectTimeout}ms`));
      }, this.transport.connectTimeout);

      client.on('connect', () => {
        this.reconnectAttempts = 0;
        settle();
      });

      client.on('error', (error) => {
        settle(error);
      });
    });
  }

  private registerConnectionLifecycle(client: MqttClient): void {
    client.on('reconnect', () => {
      this.connectionState = 'reconnecting';
      this.reconnectAttempts++;
      const max = this.transport.maxReconnectAttempts;
      if (max > 0 && this.reconnectAttempts >= max) {
        this.reconnectExhausted = true;
        client.end(true);
      }
    });

    client.on('close', () => {
      if (this.intentionalShutdown || this.reconnectExhausted) {
        this.connectionState = 'disconnected';
        return;
      }
      this.connectionState = this.transport.reconnectPeriod === 0 ? 'disconnected' : 'reconnecting';
    });

    client.on('offline', () => {
      this.connectionState = 'reconnecting';
    });
  }

  private notifyConnected(): void {
    for (const handler of this.connectedHandlers) {
      Promise.resolve(handler()).catch((error: unknown) => {
        logger.warn('MQTT connected handler failed', {
          error: error instanceof Error ? error.message : 'Unknown connected handler error',
        });
      });
    }
  }

  private requireClient(): MqttClient {
    if (!this.client || !this.client.connected) {
      throw new Error('MQTT client is not connected');
    }
    return this.client;
  }
}
