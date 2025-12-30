import { mqttConfig } from "./config/mqtt";
import { logger } from "./helper/logger";
import { commandHandler } from "./modbus/commandHandler";
import { mqttClientManager } from "./mqtt/mqttClientManager";
import { modbusService } from "./services/modbusService";
import { provisioningService } from "./services/provisioningService";
import { provisioningRegistrationService } from "./services/provisioningRegistrationService";
import { credentialsService } from "./services/credentialsService";

async function main() {
  logger.info("Starting the application...");

  try {
    // Check provisioning state
    const isProvisioned = provisioningService.getProvisioningState();
    logger.info(`Locker provisioning status: ${isProvisioned ? "PROVISIONED" : "NOT PROVISIONED"}`);

    if (!isProvisioned) {
      // Check if provisioning token is set
      if (!mqttConfig.provisioningToken) {
        logger.error("PROVISIONING_TOKEN environment variable is not set");
        logger.error("Cannot start provisioning process without a token");
        process.exit(1);
      }

      logger.info("Starting provisioning process...");
      
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
          mqttConfig.provisioningToken,
          mqttConfig.clientId
        );

        if (success) {
          logger.info("Provisioning completed successfully!");
          logger.info("Reconnecting with new credentials...");

          // Disconnect and reconnect with new credentials
          await mqttClientManager.disconnect();

          // Wait 5 seconds before reconnecting
          logger.info("Waiting 5 seconds before reconnecting...");
          await new Promise(resolve => setTimeout(resolve, 5000));

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
        }
      } catch (error) {
        logger.error("Provisioning failed:", error);
        process.exit(1);
      }
    } else {
      // Initialize MQTT connection with saved credentials
      await mqttClientManager.connect(mqttConfig.brokerUrl, {
        username: mqttConfig.username,
        password: mqttConfig.password,
        clientId: mqttConfig.clientId,
      });

      logger.info("MQTT connection established");
    }

    // Only initialize Modbus if provisioned
    if (provisioningService.getProvisioningState()) {
      await modbusService.connect();
      logger.info("Modbus RTU connection established");

      // Start monitoring coils
      setInterval(async () => {
        try {
          const coils = await modbusService.readCoils(0x0000, 1);
          logger.debug("Initial coil status 0:", coils);
        }
        catch (error) {
          logger.error("Error reading initial coil status:", error);
        }
      }, 5000); // Read every 5 seconds
    } else {
      logger.warn("Locker is not provisioned. Modbus connection skipped.");
    }

    // Handle graceful shutdown
    process.on("SIGINT", gracefulShutdown);
    process.on("SIGTERM", gracefulShutdown);

    logger.info("Application started successfully")
  } catch (error) {
    logger.error("Failed to start application:", error);
    process.exit(1);
  }
}

async function gracefulShutdown() {
  logger.info("Shutting down gracefully...");

  try {
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
