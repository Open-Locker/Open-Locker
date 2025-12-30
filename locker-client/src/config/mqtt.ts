import dotenv from "dotenv";
import fs from "fs";
import path from "path";
import { randomBytes } from "crypto";
import { credentialsService } from "../services/credentialsService";

dotenv.config();

const CLIENT_ID_FILE = path.join(process.cwd(), ".mqtt-client-id");

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

  // Fall back to environment variables
  return {
    username: process.env.MQTT_USERNAME,
    password: process.env.MQTT_PASSWORD,
  };
}

export const mqttConfig = {
  brokerUrl: process.env.MQTT_BROKER_URL || "mqtt://localhost:1883",
  defaultUsername: process.env.MQTT_DEFAULT_USERNAME || "default",
  defaultPassword: process.env.MQTT_DEFAULT_PASSWORD || "default",
  ...getCredentials(),
  clientId: getOrGenerateClientId(),
  provisioningToken: process.env.PROVISIONING_TOKEN,
  heartbeatInterval: parseInt(process.env.HEARTBEAT_INTERVAL || "15", 10) * 1000, // Convert to milliseconds
};
