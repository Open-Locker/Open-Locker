import { withMessageId } from "../helper/mqttMessage";
import { logger } from "../helper/logger";
import { mqttClientManager } from "../mqtt/mqttClientManager";

export function prepareMQTTPayload(message: string | object): string {
  if (typeof message === "string") {
    return message;
  }

  const envelope =
    "message_id" in message ? message : withMessageId(message);

  return JSON.stringify(envelope);
}

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

    const payload = prepareMQTTPayload(message);

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
}

export const mqttService = new MQTTService();
