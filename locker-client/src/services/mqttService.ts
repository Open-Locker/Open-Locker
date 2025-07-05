import { mqttConfig } from "../config/mqtt";
import { logger } from "../helper/logger";
import { commandHandler } from "../modbus/commandHandler";
import { mqttClientManager } from "../mqtt/mqttClientManager";

export class MQTTService {
  async publish(
    topic: string,
    message: string | object,
    options?: { qos?: 0 | 1 | 2; retain?: boolean }
  ): Promise<void> {
    const client = mqttClientManager.getClient();

    if (!client || !client.connected) {
      throw new Error("MQTT client is not connected");
    }

    const payload =
      typeof message === "string" ? message : JSON.stringify(message);

    return new Promise((resolve, reject) => {
      client.publish(topic, payload, options || { qos: 1 }, (error) => {
        if (error) {
          logger.error(`Failed to publish to ${topic}:`, error);
          reject(error);
        } else {
          logger.debug(`Message published to ${topic}: ${payload}`);
          resolve();
        }
      });
    });
  }

  async subscribe(topic: string): Promise<void> {
    const client = mqttClientManager.getClient();

    if (!client || !client.connected) {
      throw new Error("MQTT client is not connected");
    }

    return new Promise((resolve, reject) => {
      client.subscribe(topic, { qos: 1 }, (error) => {
        if (error) {
          logger.error(`Failed to subscribe to ${topic}:`, error);
          reject(error);
        } else {
          logger.info(`Subscribed to ${topic}`);
          resolve();
        }
      });
    });
  }

  async subscribeToCommands(): Promise<void> {
    const client = mqttClientManager.getClient();

    if (!client) {
      throw new Error("MQTT client is not available");
    }

    await this.subscribe(mqttConfig.topics.open);

    client.on("message", async (topic, message) => {
      if (topic === mqttConfig.topics.open) {
        try {
          const compartment = JSON.parse(message.toString());
          await commandHandler.handleOpenCompartment(compartment);
        } catch (error) {
          logger.error("Failed to parse or handle command:", error);
        }
      }
    });
  }

  async publishStatus(status: any): Promise<void> {
    await this.publish(mqttConfig.topics.status, status);
  }

  async publishRegistration(lockerData: any): Promise<void> {
    await this.publish(mqttConfig.topics.registration, lockerData);
  }
}

export const mqttService = new MQTTService();
