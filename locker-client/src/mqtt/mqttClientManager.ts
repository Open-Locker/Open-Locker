import mqtt, { MqttClient } from "mqtt";
import { getMqttTransportSettings } from "../config/mqtt";
import { logger } from "../helper/logger";

/**
 * - disconnected: no client or client ended
 * - connecting: initial TCP/MQTT handshake in progress
 * - connected: session up
 * - reconnecting: broker link lost; mqtt.js is retrying per reconnectPeriod
 */
export type MqttConnectionState =
  | "disconnected"
  | "connecting"
  | "connected"
  | "reconnecting";

/**
 * Single MQTT client for the process. Expected usage: sequential connect from
 * application startup (e.g. `app.ts`), then optional `disconnect` before a new
 * `connect` after provisioning.
 *
 * Concurrent `connect()` calls while a handshake is in flight share the same
 * Promise and resolve or reject together. If a second caller passes a
 * different `brokerUrl` during that window, it throws — the in-flight attempt
 * is not cancelled.
 */
class MQTTClientManager {
  private client: MqttClient | null = null;
  private reconnectAttempts = 0;
  private connectionState: MqttConnectionState = "disconnected";
  /** True only for explicit `disconnect()` (graceful shutdown). */
  private intentionalShutdown = false;
  /** True when optional max reconnect cap was hit. */
  private reconnectExhausted = false;

  /** Shared by concurrent callers during the initial handshake only. */
  private connectInFlight: Promise<MqttClient> | null = null;
  /** Broker URL for the handshake in `connectInFlight` (for mismatch checks). */
  private pendingConnectBrokerUrl: string | null = null;

  getConnectionState(): MqttConnectionState {
    return this.connectionState;
  }

  async connect(
    brokerUrl: string,
    options?: mqtt.IClientOptions,
  ): Promise<MqttClient> {
    if (this.client?.connected) {
      return this.client;
    }

    if (this.connectInFlight) {
      if (this.pendingConnectBrokerUrl !== brokerUrl) {
        throw new Error(
          "MQTT connect already in progress to a different broker URL",
        );
      }
      return this.connectInFlight;
    }

    this.pendingConnectBrokerUrl = brokerUrl;
    this.connectInFlight = this.connectNewClient(brokerUrl, options);
    try {
      return await this.connectInFlight;
    } finally {
      this.connectInFlight = null;
      this.pendingConnectBrokerUrl = null;
    }
  }

  private connectNewClient(
    brokerUrl: string,
    options?: mqtt.IClientOptions,
  ): Promise<MqttClient> {
    this.intentionalShutdown = false;
    this.reconnectExhausted = false;
    this.connectionState = "connecting";

    const transport = getMqttTransportSettings();

    return new Promise((resolve, reject) => {
      const clientOptions: mqtt.IClientOptions = {
        keepalive: transport.keepalive,
        clean: transport.clean,
        reconnectPeriod: transport.reconnectPeriod,
        connectTimeout: transport.connectTimeout,
        ...options,
      };

      this.client = mqtt.connect(brokerUrl, clientOptions);

      let initialConnectSettled = false;
      let isFirstConnectForThisClient = true;

      this.client.on("connect", () => {
        this.reconnectAttempts = 0;
        this.connectionState = "connected";
        initialConnectSettled = true;
        if (isFirstConnectForThisClient) {
          logger.info("MQTT client connected successfully");
          isFirstConnectForThisClient = false;
        } else {
          logger.info("MQTT session re-established after outage");
        }
        resolve(this.client!);
      });

      this.client.on("error", (error) => {
        logger.error("MQTT connection error:", error);
        if (!initialConnectSettled) {
          this.connectionState = "disconnected";
          reject(error);
        }
      });

      this.client.on("reconnect", () => {
        this.connectionState = "reconnecting";
        this.reconnectAttempts++;
        const max = transport.maxReconnectAttempts;
        const cap = max > 0 ? `/${max}` : "";
        logger.info(
          `MQTT reconnecting... attempt ${this.reconnectAttempts}${max > 0 ? cap : " (unlimited)"}`,
        );

        if (max > 0 && this.reconnectAttempts >= max) {
          logger.error(
            "Max MQTT reconnection attempts reached; stopping client",
          );
          this.reconnectExhausted = true;
          this.client?.end(true);
        }
      });

      this.client.on("close", () => {
        logger.info("MQTT connection closed");
        if (this.intentionalShutdown) {
          this.connectionState = "disconnected";
          return;
        }
        if (this.reconnectExhausted) {
          this.connectionState = "disconnected";
          return;
        }
        if (transport.reconnectPeriod === 0) {
          this.connectionState = "disconnected";
          return;
        }
        this.connectionState = "reconnecting";
      });

      this.client.on("offline", () => {
        this.connectionState = "reconnecting";
        logger.warn(
          "MQTT client offline — waiting for broker (automatic reconnect)",
        );
      });
    });
  }

  getClient(): MqttClient | null {
    return this.client;
  }

  async disconnect(): Promise<void> {
    if (this.client) {
      return new Promise((resolve) => {
        this.intentionalShutdown = true;
        this.connectionState = "disconnected";
        this.client!.end(false, () => {
          logger.info("MQTT client disconnected");
          this.client = null;
          this.intentionalShutdown = false;
          resolve();
        });
      });
    }
  }

  isConnected(): boolean {
    return this.client?.connected ?? false;
  }
}

export const mqttClientManager = new MQTTClientManager();
