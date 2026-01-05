import { logger } from "../helper/logger";
import { mqttClientManager } from "../mqtt/mqttClientManager";
import { mqttService } from "./mqttService";
import { credentialsService } from "./credentialsService";
import { provisioningService as provisioningStateService } from "./provisioningService";

interface ProvisioningSuccessResponse {
  status: "success";
  data: {
    mqtt_user: string;
    mqtt_password: string;
  };
}

interface ProvisioningErrorResponse {
  status: "error";
  message: string;
}

type ProvisioningResponse = ProvisioningSuccessResponse | ProvisioningErrorResponse;

export class ProvisioningRegistrationService {
  private readonly PROVISIONING_TIMEOUT = 30000; // 30 seconds

  async register(provisioningToken: string, clientId: string): Promise<boolean> {
    const replyTopic = `locker/provisioning/reply/${clientId}`;
    const registerTopic = `locker/register/${provisioningToken}`;

    logger.info(`Starting provisioning registration...`);
    logger.info(`Reply topic: ${replyTopic}`);
    logger.info(`Register topic: ${registerTopic}`);

    try {
      // Subscribe to reply topic
      await mqttService.subscribe(replyTopic);
      logger.info(`Subscribed to reply topic: ${replyTopic}`);

      // Set up message handler
      const responsePromise = this.waitForProvisioningResponse(replyTopic, clientId);

      // Send registration request
      await mqttService.publish(registerTopic, {
        client_id: clientId,
      });
      logger.info(`Sent registration request to ${registerTopic}`);

      // Wait for response
      const success = await responsePromise;
      return success;
    } catch (error) {
      logger.error("Provisioning registration failed:", error);
      throw error;
    }
  }

  private waitForProvisioningResponse(replyTopic: string, clientId: string): Promise<boolean> {
    return new Promise((resolve, reject) => {
      const timeout = setTimeout(() => {
        cleanup();
        reject(new Error("Provisioning timeout - no response received"));
      }, this.PROVISIONING_TIMEOUT);

      const messageHandler = async (topic: string, message: Buffer) => {
        if (topic !== replyTopic) {
          return;
        }

        try {
          const response: ProvisioningResponse = JSON.parse(message.toString());
          logger.info("Received provisioning response:", response);

          cleanup();

          if (response.status === "success") {
            // Save credentials
            credentialsService.saveCredentials(
              response.data.mqtt_user,
              response.data.mqtt_password
            );
            logger.info("Provisioning successful! Credentials saved.");

            // Mark as provisioned
            provisioningStateService.markAsProvisioned();

            resolve(true);
          } else {
            logger.error(`Provisioning failed: ${response.message}`);
            reject(new Error(response.message));
          }
        } catch (error) {
          cleanup();
          logger.error("Failed to parse provisioning response:", error);
          reject(error);
        }
      };

      const cleanup = () => {
        clearTimeout(timeout);
        const client = mqttClientManager.getClient();
        if (client) {
          client.off("message", messageHandler);
        }
      };

      const client = mqttClientManager.getClient();
      if (client) {
        client.on("message", messageHandler);
      } else {
        cleanup();
        reject(new Error("MQTT client not available"));
      }
    });
  }
}

export const provisioningRegistrationService = new ProvisioningRegistrationService();
