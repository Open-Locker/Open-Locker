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

class MQTTClientManager {
  private client: MqttClient | null = null;
  private isConnecting = false;
  private reconnectAttempts = 0;
  private connectionState: MqttConnectionState = "disconnected";
  /** True only for explicit `disconnect()` (graceful shutdown). */
  private intentionalShutdown = false;
  /** True when optional max reconnect cap was hit. */
  private reconnectExhausted = false;

  getConnectionState(): MqttConnectionState {
    return this.connectionState;
  }

  async connect(
    brokerUrl: string,
    options?: mqtt.IClientOptions,
  ): Promise<MqttClient> {
    if (this.client && this.client.connected) {
      return this.client;
    }

    if (this.isConnecting) {
      return new Promise((resolve, reject) => {
        const checkConnection = () => {
          if (this.client && this.client.connected) {
            resolve(this.client);
          } else if (!this.isConnecting) {
            reject(new Error("Connection failed"));
          } else {
            setTimeout(checkConnection, 100);
          }
        };
        checkConnection();
      });
    }

    this.isConnecting = true;
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
        this.isConnecting = false;
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
        this.isConnecting = false;
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
        this.isConnecting = false;
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
