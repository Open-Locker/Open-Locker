import fs from "fs";
import { z } from "zod";
import { logger } from "../helper/logger";
import { MQTT_CREDENTIALS_FILE } from "../config/paths";

const CREDENTIALS_FILE = MQTT_CREDENTIALS_FILE;

/** Shape of `.mqtt-credentials.json` on disk (validated on read/write). */
export const mqttCredentialsFileSchema = z.object({
  username: z.string().min(1),
  password: z.string().min(1),
});

export type MqttCredentials = z.infer<typeof mqttCredentialsFileSchema>;

export class CredentialsService {
  private cachedCredentials: MqttCredentials | null = null;

  public getCredentials(): MqttCredentials | null {
    if (this.cachedCredentials) {
      return this.cachedCredentials;
    }

    try {
      if (fs.existsSync(CREDENTIALS_FILE)) {
        const data = fs.readFileSync(CREDENTIALS_FILE, "utf-8");
        const raw: unknown = JSON.parse(data);
        const parsed = mqttCredentialsFileSchema.safeParse(raw);
        if (!parsed.success) {
          logger.error(
            "Persisted MQTT credentials file has invalid shape",
            parsed.error.flatten(),
          );
          return null;
        }
        this.cachedCredentials = parsed.data;
        logger.info("Loaded persisted MQTT credentials");
        return parsed.data;
      }
    } catch (error) {
      logger.error("Failed to load persisted credentials:", error);
    }
    return null;
  }

  public saveCredentials(username: string, password: string): void {
    const parsed = mqttCredentialsFileSchema.safeParse({ username, password });
    if (!parsed.success) {
      logger.error(
        "Refusing to save invalid MQTT credentials",
        parsed.error.flatten(),
      );
      throw new Error("Invalid MQTT credentials");
    }

    try {
      fs.writeFileSync(
        CREDENTIALS_FILE,
        JSON.stringify(parsed.data, null, 2),
        "utf-8",
      );
      this.cachedCredentials = parsed.data;
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
