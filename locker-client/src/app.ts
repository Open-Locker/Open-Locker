import { mqttConfig } from "./config/mqtt";
import { logger } from "./helper/logger";
import { mqttClientManager } from "./mqtt/mqttClientManager";
import { modbusService } from "./services/modbusService";
import { mqttService } from "./services/mqttService";

async function main() {
  logger.info("Starting the application...");

  try {
    // Initialize Modbus RTU connection
    await modbusService.connect();
    logger.info("Modbus RTU connection established");

    // Initialize MQTT connection
    await mqttClientManager.connect(mqttConfig.brokerUrl, {
      username: mqttConfig.username,
      password: mqttConfig.password,
      clientId: mqttConfig.clientId,
    });

    logger.info("MQTT connection established");

    // Subscribe to command topic
    await mqttService.subscribeToCommands();
    logger.info("Subscribed to command topic");

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
