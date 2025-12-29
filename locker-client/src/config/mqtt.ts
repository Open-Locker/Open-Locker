import dotenv from "dotenv";
import fs from "fs";
import path from "path";
import { randomBytes } from "crypto";

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

export const mqttConfig = {
  brokerUrl: process.env.MQTT_BROKER_URL || "mqtt://localhost:1883",
  username: process.env.MQTT_USERNAME,
  password: process.env.MQTT_PASSWORD,
  clientId: getOrGenerateClientId(),
  topics: {
    registration: process.env.MQTT_TOPIC_REGISTRATION || "locker/registration",
    status: process.env.MQTT_TOPIC_STATUS || "locker/simon/status",
    open: process.env.MQTT_TOPIC_COMMANDS || "locker/simon/open",
  },
};
