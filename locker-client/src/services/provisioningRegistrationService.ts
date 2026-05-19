import { logger } from "../helper/logger";
import { mqttClientManager } from "../mqtt/mqttClientManager";
import { mqttService } from "./mqttService";
import { credentialsService } from "./credentialsService";
import { provisioningService as provisioningStateService } from "./provisioningService";
import { z } from "zod";

const nonEmptyStringSchema = z.string().trim().min(1);

export const provisioningSuccessResponseSchema = z.object({
  message_id: nonEmptyStringSchema,
  status: z.literal("success"),
  timestamp: nonEmptyStringSchema,
  data: z.object({
    mqtt_user: nonEmptyStringSchema,
    mqtt_password: nonEmptyStringSchema,
  }),
});

export const provisioningErrorResponseSchema = z.object({
  message_id: nonEmptyStringSchema,
  status: z.literal("error"),
  timestamp: nonEmptyStringSchema,
  message: nonEmptyStringSchema,
});

export const provisioningResponseSchema = z.discriminatedUnion("status", [
  provisioningSuccessResponseSchema,
  provisioningErrorResponseSchema,
]);

export type ProvisioningSuccessResponse = z.infer<typeof provisioningSuccessResponseSchema>;
export type ProvisioningErrorResponse = z.infer<typeof provisioningErrorResponseSchema>;
export type ProvisioningResponse = z.infer<typeof provisioningResponseSchema>;

export function parseProvisioningResponse(response: unknown): ProvisioningResponse {
  const parsed = provisioningResponseSchema.safeParse(response);
  if (!parsed.success) {
    logger.error("Received malformed provisioning response", parsed.error.flatten());
    throw new Error("Malformed provisioning response");
  }

  return parsed.data;
}

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
        timestamp: new Date().toISOString(),
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
          const response = parseProvisioningResponse(JSON.parse(message.toString()));
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
