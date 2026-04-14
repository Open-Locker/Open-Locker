import { getMqttConfig, logCurrentMqttConfig } from "./config/mqtt";
import { logger } from "./helper/logger";
import { ensureDirectories } from "./helper/directories";
import { commandHandler } from "./modbus/commandHandler";
import { mqttClientManager } from "./mqtt/mqttClientManager";
import { MQTTMessageHandler } from "./mqtt/mqttMessageHandler";
import { modbusService } from "./services/modbusService";
import { provisioningService } from "./services/provisioningService";
import { provisioningRegistrationService } from "./services/provisioningRegistrationService";
import { credentialsService } from "./services/credentialsService";
import { heartbeatService } from "./services/heartbeatService";
import { coilPollingService } from "./services/coilPollingService";
import { provisioningTokenService } from "./services/provisioningTokenService";

const mqttMessageHandler = new MQTTMessageHandler(() => commandHandler);

async function main() {
  logger.info("Starting the application...");

  try {
    // Ensure required directories exist
    ensureDirectories();
    logCurrentMqttConfig();
    // Check provisioning state
    const isProvisioned = provisioningService.getProvisioningState();
    logger.info(
      `Locker provisioning status: ${
        isProvisioned ? "PROVISIONED" : "NOT PROVISIONED"
      }`,
    );

    if (!isProvisioned) {
      // Read and delete provisioning token from file
      const provisioningToken = provisioningTokenService.readAndDeleteToken();

      if (!provisioningToken) {
        logger.error("No provisioning token found");
        logger.error("Cannot start provisioning process without a token");
        logger.error(
          "Please provide the PROVISIONING_TOKEN environment variable",
        );
        process.exit(1);
      }

      logger.info("Starting provisioning process...");
      const mqttConfig = getMqttConfig();

      // Connect with default credentials
      await mqttClientManager.connect(mqttConfig.brokerUrl, {
        username: mqttConfig.defaultUsername,
        password: mqttConfig.defaultPassword,
        clientId: mqttConfig.clientId,
      });

      logger.info("MQTT connection established with default credentials");

      // Start provisioning registration
      try {
        const success = await provisioningRegistrationService.register(
          provisioningToken,
          mqttConfig.clientId,
        );

        if (success) {
          logger.info("Provisioning completed successfully!");
          logger.info("Reconnecting with new credentials...");

          // Disconnect and reconnect with new credentials
          await mqttClientManager.disconnect();

          // Wait 5 seconds before reconnecting
          logger.info("Waiting 5 seconds before reconnecting...");
          await new Promise((resolve) => setTimeout(resolve, 5000));

          // Get the new credentials directly from credentials service
          const newCredentials = credentialsService.getCredentials();

          if (!newCredentials) {
            throw new Error("Failed to load new credentials");
          }

          await mqttClientManager.connect(mqttConfig.brokerUrl, {
            username: newCredentials.username,
            password: newCredentials.password,
            clientId: mqttConfig.clientId,
          });

          logger.info("MQTT reconnected with provisioned credentials");

          // Initialize MQTT message handler
          await mqttMessageHandler.initialize();
          logger.info("MQTT message handler initialized");

          // Start heartbeat service
          heartbeatService.start();
        }
      } catch (error) {
        logger.error("Provisioning failed:", error);
        process.exit(1);
      }
    } else {
      const mqttConfig = getMqttConfig();

      // Initialize MQTT connection with saved credentials
      await mqttClientManager.connect(mqttConfig.brokerUrl, {
        username: mqttConfig.username,
        password: mqttConfig.password,
        clientId: mqttConfig.clientId,
      });

      logger.info("MQTT connection established");

      // Initialize MQTT message handler
      await mqttMessageHandler.initialize();
      logger.info("MQTT message handler initialized");

      // Start heartbeat service
      heartbeatService.start();
    }

    // Only initialize Modbus if provisioned
    if (provisioningService.getProvisioningState()) {
      await modbusService.connect();
      logger.info("Modbus RTU connection established");

      const configuredSlaveIds = modbusService.getConfiguredSlaveIds();
      const reachableSlaveIds: number[] = [];
      const startupFailsafeFailures: number[] = [];

      for (const slaveId of configuredSlaveIds) {
        try {
          await modbusService.turnAllRelaysOff(slaveId);
          reachableSlaveIds.push(slaveId);
        } catch (error) {
          startupFailsafeFailures.push(slaveId);
          logger.error(
            `[slave:${slaveId}] Startup failsafe failed, continuing without relay reset for this board:`,
            error,
          );
        }
      }

      if (
        configuredSlaveIds.length > 0 && reachableSlaveIds.length === 0
      ) {
        throw new Error(
          `Startup failsafe failed for all configured Modbus boards: ${configuredSlaveIds.join(", ")}`,
        );
      }

      if (startupFailsafeFailures.length > 0) {
        logger.warn(
          `Startup failsafe skipped unreachable boards: ${startupFailsafeFailures.join(", ")}`,
        );
      }

      logger.info(
        `Startup failsafe completed for reachable boards: ${reachableSlaveIds.join(", ")}`,
      );

      // Start coil polling service
      coilPollingService.start();
    } else {
      logger.warn("Locker is not provisioned. Modbus connection skipped.");
    }

    // Handle graceful shutdown
    process.on("SIGINT", gracefulShutdown);
    process.on("SIGTERM", gracefulShutdown);

    logger.info("Application started successfully");
  } catch (error) {
    logger.error("Failed to start application:", error);
    process.exit(1);
  }
}

async function gracefulShutdown() {
  logger.info("Shutting down gracefully...");

  try {
    // Stop heartbeat service
    heartbeatService.stop();

    // Stop coil polling service
    coilPollingService.stop();

    // Stop all compartment monitoring
    commandHandler.stopAllMonitoring();

    await Promise.all([
      mqttClientManager.disconnect(),
      modbusService.disconnect(),
    ]);
    logger.info("All connections closed");
  } catch (error) {
    logger.error("Error during shutdown:", error);
  }

  process.exit(0);
}

main();
