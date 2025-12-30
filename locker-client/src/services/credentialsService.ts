import fs from "fs";
import path from "path";
import { logger } from "../helper/logger";

const CREDENTIALS_FILE = path.join(process.cwd(), ".mqtt-credentials.json");

interface MqttCredentials {
  username: string;
  password: string;
}

export class CredentialsService {
  public getCredentials(): MqttCredentials | null {
    try {
      if (fs.existsSync(CREDENTIALS_FILE)) {
        const data = fs.readFileSync(CREDENTIALS_FILE, "utf-8");
        const credentials = JSON.parse(data);
        logger.info("Loaded persisted MQTT credentials");
        return credentials;
      }
    } catch (error) {
      logger.error("Failed to load persisted credentials:", error);
    }
    return null;
  }

  public saveCredentials(username: string, password: string): void {
    try {
      const credentials: MqttCredentials = { username, password };
      fs.writeFileSync(CREDENTIALS_FILE, JSON.stringify(credentials, null, 2), "utf-8");
      logger.info("MQTT credentials persisted successfully");
    } catch (error) {
      logger.error("Failed to persist credentials:", error);
      throw error;
    }
  }

  public hasCredentials(): boolean {
    return fs.existsSync(CREDENTIALS_FILE);
  }
}

export const credentialsService = new CredentialsService();
