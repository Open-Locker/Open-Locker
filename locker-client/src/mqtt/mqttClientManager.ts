import mqtt, { MqttClient } from "mqtt";
import { logger } from "../helper/logger";

class MQTTClientManager {
  private client: MqttClient | null = null;
  private isConnecting = false;
  private reconnectAttempts = 0;
  private maxReconnectAttempts = 5;

  async connect(
    brokerUrl: string,
    options?: mqtt.IClientOptions
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

    return new Promise((resolve, reject) => {
      const clientOptions: mqtt.IClientOptions = {
        keepalive: 60,
        clean: true,
        reconnectPeriod: 5000,
        connectTimeout: 30000,
        ...options,
      };

      this.client = mqtt.connect(brokerUrl, clientOptions);

      this.client.on("connect", () => {
        logger.info("MQTT client connected successfully");
        this.isConnecting = false;
        this.reconnectAttempts = 0;
        resolve(this.client!);
      });

      this.client.on("error", (error) => {
        logger.error("MQTT connection error:", error);
        this.isConnecting = false;
        reject(error);
      });

      this.client.on("reconnect", () => {
        this.reconnectAttempts++;
        logger.info(`MQTT reconnecting... Attempt ${this.reconnectAttempts}`);

        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
          logger.error("Max reconnection attempts reached");
          this.client?.end();
        }
      });

      this.client.on("close", () => {
        logger.info("MQTT connection closed");
        this.isConnecting = false;
      });

      this.client.on("offline", () => {
        logger.warn("MQTT client is offline");
      });
    });
  }

  getClient(): MqttClient | null {
    return this.client;
  }

  async disconnect(): Promise<void> {
    if (this.client) {
      return new Promise((resolve) => {
        this.client!.end(false, () => {
          logger.info("MQTT client disconnected");
          this.client = null;
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
