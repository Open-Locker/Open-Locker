import { withMessageId } from "../helper/mqttMessage";
import { logger } from "../helper/logger";
import { credentialsService } from "../services/credentialsService";
import { mqttDedupService } from "../services/mqttDedupService";
import { mqttService } from "../services/mqttService";
import {
  ErrorResponse,
  isMQTTCommand,
  isOpenCompartmentCommand,
  MQTTErrorCode,
  OpenCompartmentCommand,
  SuccessResponse,
} from "../types/mqtt";
import { commandHandler } from "../modbus/commandHandler";
import { mqttClientManager } from "../mqtt/mqttClientManager";

/**
 * Handles incoming MQTT commands and coordinates responses
 */
export class MQTTMessageHandler {
  private lockerUuid: string | null = null;

  /**
   * Initialize the message handler and subscribe to command topics
   */
  async initialize(): Promise<void> {
    const credentials = credentialsService.getCredentials();
    if (!credentials || !credentials.username) {
      throw new Error(
        "Cannot initialize MQTT handler: No credentials available",
      );
    }

    this.lockerUuid = credentials.username;
    logger.info(
      `Initializing MQTT message handler for locker: ${this.lockerUuid}`,
    );

    // Subscribe to command topic
    const commandTopic = `locker/${this.lockerUuid}/command`;
    await mqttService.subscribe(commandTopic);

    // Set up message listener
    this.setupMessageListener();
  }

  /**
   * Set up the MQTT message listener
   */
  private setupMessageListener(): void {
    const client = mqttClientManager.getClient();
    if (!client) {
      throw new Error("MQTT client not available");
    }

    client.on("message", async (topic: string, payload: Buffer) => {
      try {
        const message = payload.toString();
        logger.debug(`Received message on topic ${topic}: ${message}`);

        // Only process command messages
        if (topic === `locker/${this.lockerUuid}/command`) {
          await this.handleCommand(message);
        }
      } catch (error) {
        logger.error("Error processing MQTT message:", error);
      }
    });
  }

  /**
   * Handle an incoming command message
   */
  private async handleCommand(messageStr: string): Promise<void> {
    let command: unknown;

    try {
      command = JSON.parse(messageStr);
    } catch (error) {
      logger.error("Failed to parse command JSON:", error);
      return;
    }

    // Validate command envelope before any side effects
    if (!isMQTTCommand(command)) {
      logger.error("Rejected command without required IDs:", command);
      return;
    }

    if (mqttDedupService.hasSeenMessageId(command.message_id)) {
      logger.info(
        `Ignoring duplicate MQTT packet ${command.message_id} for transaction ${command.transaction_id}`,
      );
      return;
    }

    mqttDedupService.rememberMessageId(command.message_id);

    logger.info(
      `Processing command: ${command.action} (${command.transaction_id}, packet ${command.message_id})`,
    );

    const existingRecord = mqttDedupService.getCommandRecord(
      command.transaction_id,
    );

    if (existingRecord?.status === "completed") {
      logger.info(
        `Ignoring duplicate transaction ${command.transaction_id} because it was already completed`,
      );
      return;
    }

    if (existingRecord?.status === "in_progress") {
      logger.info(
        `Ignoring duplicate transaction ${command.transaction_id} while execution is still in progress`,
      );
      return;
    }

    mqttDedupService.markCommandInProgress(
      command.transaction_id,
      command.action,
    );

    // Route to specific command handler
    try {
      if (isOpenCompartmentCommand(command)) {
        await this.handleOpenCompartment(command);
      } else {
        logger.warn(`Unknown command action: ${command.action}`);
        await this.sendErrorResponse(
          command.transaction_id,
          command.action,
          MQTTErrorCode.INVALID_COMMAND,
          `Unknown action: ${command.action}`,
        );
        mqttDedupService.markCommandCompleted(
          command.transaction_id,
          command.action,
        );
      }
    } catch (error) {
      logger.error(`Error executing command ${command.action}:`, error);
      await this.sendErrorResponse(
        command.transaction_id,
        command.action,
        MQTTErrorCode.UNKNOWN_ERROR,
        error instanceof Error ? error.message : "Unknown error occurred",
      );
      mqttDedupService.markCommandCompleted(
        command.transaction_id,
        command.action,
      );
    }
  }

  /**
   * Handle the open_compartment command
   */
  private async handleOpenCompartment(
    command: OpenCompartmentCommand,
  ): Promise<void> {
    const { compartment_number } = command.data;
    const { transaction_id, action } = command;

    logger.info(
      `Opening compartment ${compartment_number} (transaction: ${transaction_id})`,
    );

    try {
      // Execute the command via the command handler
      await commandHandler.handleOpenCompartment(compartment_number);

      // Send success response
      await this.sendSuccessResponse(
        transaction_id,
        action,
        `Compartment ${compartment_number} opened successfully.`,
      );
      mqttDedupService.markCommandCompleted(transaction_id, action);
    } catch (error) {
      logger.error(`Failed to open compartment ${compartment_number}:`, error);

      // Determine error code based on error type
      let errorCode = MQTTErrorCode.UNKNOWN_ERROR;
      let errorMessage = "Failed to open compartment";

      if (error instanceof Error) {
        errorMessage = error.message;

        // Check for specific error types
        if (error.message.toLowerCase().includes("modbus")) {
          errorCode = MQTTErrorCode.MODBUS_ERROR;
        } else if (error.message.toLowerCase().includes("timeout")) {
          errorCode = MQTTErrorCode.TIMEOUT;
        } else if (error.message.toLowerCase().includes("jammed")) {
          errorCode = MQTTErrorCode.DOOR_JAMMED;
        } else if (error.message.toLowerCase().includes("not found")) {
          errorCode = MQTTErrorCode.COMPARTMENT_NOT_FOUND;
        } else {
          errorCode = MQTTErrorCode.HARDWARE_ERROR;
        }
      }

      await this.sendErrorResponse(
        transaction_id,
        action,
        errorCode,
        errorMessage,
      );
      mqttDedupService.markCommandCompleted(transaction_id, action);
    }
  }

  /**
   * Send a success response to the backend
   */
  private async sendSuccessResponse(
    transaction_id: string,
    action: string,
    message: string,
  ): Promise<SuccessResponse> {
    if (!this.lockerUuid) {
      throw new Error("Cannot send response: locker UUID not set");
    }

    const response: SuccessResponse = withMessageId({
      type: "command_response",
      action,
      result: "success",
      transaction_id,
      timestamp: new Date().toISOString(),
      message,
    });

    await this.publishResponse(response);
    logger.info(`Success response sent for transaction ${transaction_id}`);
    return response;
  }

  /**
   * Send an error response to the backend
   */
  private async sendErrorResponse(
    transaction_id: string,
    action: string,
    error_code: MQTTErrorCode,
    message: string,
  ): Promise<ErrorResponse> {
    if (!this.lockerUuid) {
      throw new Error("Cannot send response: locker UUID not set");
    }

    const response: ErrorResponse = withMessageId({
      type: "command_response",
      action,
      result: "error",
      transaction_id,
      timestamp: new Date().toISOString(),
      error_code,
      message,
    });

    await this.publishResponse(response);
    logger.error(
      `Error response sent for transaction ${transaction_id}: ${error_code} - ${message}`,
    );
    return response;
  }

  private async publishResponse(response: {
    type: "command_response";
    action: string;
    result: "success" | "error";
    transaction_id: string;
    timestamp: string;
    message_id: string;
    message?: string;
    error_code?: string;
  }): Promise<void> {
    if (!this.lockerUuid) {
      throw new Error("Cannot send response: locker UUID not set");
    }

    const topic = `locker/${this.lockerUuid}/response`;
    await mqttService.publish(topic, response, { qos: 1 });
  }
}

export const mqttMessageHandler = new MQTTMessageHandler();
