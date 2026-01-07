import fs from "fs";
import path from "path";
import { randomBytes } from "crypto";
import { credentialsService } from "../services/credentialsService";
import { logger } from "../helper/logger";
import { configLoader } from "./configLoader";
import { MQTT_CLIENT_ID_FILE } from "./paths";

const CLIENT_ID_FILE = MQTT_CLIENT_ID_FILE;

function getOrGenerateClientId(): string {
  // First check if provided via environment variable
  if (process.env.MQTT_CLIENT_ID) {
    return process.env.MQTT_CLIENT_ID;
  }

  // Try to read existing client ID from file
  try {
    if (fs.existsSync(CLIENT_ID_FILE)) {
      const clientId = fs.readFileSync(CLIENT_ID_FILE, "utf-8").trim();
      if (clientId) {
        console.log(`Using persisted client ID: ${clientId}`);
        return clientId;
      }
    }
  } catch (error) {
    console.warn("Failed to read persisted client ID:", error);
  }

  // Generate new client ID
  const newClientId = `locker-client-${randomBytes(4).toString("hex")}`;
  
  // Persist it for future use
  try {
    fs.writeFileSync(CLIENT_ID_FILE, newClientId, "utf-8");
    console.log(`Generated and persisted new client ID: ${newClientId}`);
  } catch (error) {
    console.warn("Failed to persist client ID:", error);
  }

  return newClientId;
}

function getCredentials(): { username?: string; password?: string } {
  // First try to load persisted credentials
  const persisted = credentialsService.getCredentials();
  if (persisted) {
    return persisted;
  }

  // No persisted credentials available
  return {};
}

function getMqttConfig() {
  const config = configLoader.loadConfig();
  const credentials = getCredentials();

  return {
    brokerUrl: config.mqtt.brokerUrl,
    defaultUsername: config.mqtt.defaultUsername,
    defaultPassword: config.mqtt.defaultPassword,
    ...credentials,
    clientId: getOrGenerateClientId(),
    heartbeatInterval: (config.mqtt.heartbeatInterval || 15) * 1000, // Convert to milliseconds
  };
}

export const mqttConfig = getMqttConfig();

logger.debug("MQTT configuration loaded:", {
  brokerUrl: mqttConfig.brokerUrl,
  username: mqttConfig.username || "(not set)",
  clientId: mqttConfig.clientId,
  heartbeatInterval: mqttConfig.heartbeatInterval,
});
