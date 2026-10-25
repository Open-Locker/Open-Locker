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
  private cancelInitialConnection: ((error: Error) => void) | null = null;
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
      this.cancelInitialConnection?.(new Error('MQTT disconnected before startup completed'));
      this.client!.end(false, () => {
        this.client = null;
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
    this.reconnectAttempts = 0;
    this.connectionState = 'connecting';

    const clientOptions = withVerifiedMqttTls(brokerUrl, {
      keepalive: this.transport.keepalive,
      clean: this.transport.clean,
      reconnectPeriod: this.transport.reconnectPeriod,
      connectTimeout: this.transport.connectTimeout,
      ...options,
    });

    let client: MqttClient;
    try {
      client = mqtt.connect(brokerUrl, clientOptions);
    } catch (error) {
      this.connectionState = 'disconnected';
      throw error;
    }
    this.client = client;
    if (this.messageHandler) {
      client.on('message', this.messageHandler);
    }

    const connectTimeout =
      typeof clientOptions.connectTimeout === 'number'
        ? clientOptions.connectTimeout
        : this.transport.connectTimeout;
    const reconnectPeriod =
      typeof clientOptions.reconnectPeriod === 'number'
        ? clientOptions.reconnectPeriod
        : this.transport.reconnectPeriod;
    return this.waitForInitialConnection(client, brokerUrl, connectTimeout, reconnectPeriod);
  }

  private waitForInitialConnection(
    client: MqttClient,
    brokerUrl: string,
    timeoutMs: number,
    reconnectPeriod: number,
  ): Promise<void> {
    return new Promise((resolve, reject) => {
      let settled = false;
      let connectTimeout: NodeJS.Timeout;
      let lastErrorLoggedAt: number | undefined;
      const isActive = (): boolean =>
        this.client === client && !this.intentionalShutdown && !this.reconnectExhausted;
      const settle = (error?: Error): void => {
        if (settled) {
          return;
        }

        settled = true;
        clearTimeout(connectTimeout);
        this.cancelInitialConnection = null;
        if (error) {
          reject(error);
          return;
        }

        resolve();
      };
      this.cancelInitialConnection = settle;

      const stop = (error: Error): void => {
        this.intentionalShutdown = true;
        this.connectionState = 'disconnected';
        settle(error);
        logger.error('MQTT connection stopped', {
          brokerUrl,
          error: error.message,
          reconnectAttempts: this.reconnectAttempts,
        });
        client.end(true);
      };

      // ADR-0014: startup diagnostics must not stop automatic broker recovery.
      connectTimeout = setTimeout(() => {
        if (!isActive()) {
          return;
        }
        if (reconnectPeriod === 0) {
          stop(new Error(`MQTT connection timed out after ${timeoutMs}ms`));
          return;
        }
        logger.warn('Still waiting for initial MQTT connection; automatic retries remain enabled', {
          brokerUrl,
          timeoutMs,
          reconnectAttempts: this.reconnectAttempts,
        });
      }, timeoutMs);

      client.on('connect', () => {
        if (!isActive()) {
          return;
        }
        this.reconnectAttempts = 0;
        this.connectionState = 'connected';
        settle();
        this.notifyConnected();
      });

      client.on('error', (error) => {
        if (!isActive()) {
          return;
        }
        if (reconnectPeriod === 0) {
          stop(error);
          return;
        }
        const now = Date.now();
        if (
          lastErrorLoggedAt !== undefined &&
          now - lastErrorLoggedAt < Math.max(1000, timeoutMs)
        ) {
          return;
        }
        lastErrorLoggedAt = now;
        logger.warn('MQTT connection error; automatic retries remain enabled', {
          brokerUrl,
          error: error.message,
          reconnectAttempts: this.reconnectAttempts,
        });
      });

      client.on('reconnect', () => {
        if (!isActive()) {
          return;
        }
        this.connectionState = 'reconnecting';
        this.reconnectAttempts++;
        const max = this.transport.maxReconnectAttempts;
        if (max > 0 && this.reconnectAttempts >= max) {
          this.reconnectExhausted = true;
          stop(new Error(`MQTT reconnect limit reached after ${max} attempts`));
        }
      });

      client.on('close', () => {
        if (!isActive()) {
          return;
        }
        if (reconnectPeriod === 0) {
          stop(new Error('MQTT connection closed with automatic reconnect disabled'));
          return;
        }
        this.connectionState = 'reconnecting';
      });

      client.on('offline', () => {
        if (!isActive()) {
          return;
        }
        this.connectionState = reconnectPeriod === 0 ? 'disconnected' : 'reconnecting';
      });
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
