import { mqttConfig } from "./config/mqtt";
import { logger } from "./helper/logger";
import { commandHandler } from "./modbus/commandHandler";
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

    setInterval(async () => {
      try {
        // Read initial coil status
        const coils = await modbusService.readCoils(0x0000, 1);
        logger.debug("Initial coil status 0:", coils);
      }
      catch (error) {
        logger.error("Error reading initial coil status:", error);
      }
    }, 5000); // Read every 5 seconds
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
