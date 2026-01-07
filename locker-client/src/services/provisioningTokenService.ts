import fs from "fs";
import { logger } from "../helper/logger";
import { PROVISIONING_TOKEN_FILE } from "../config/paths";

export class ProvisioningTokenService {
  /**
   * Read the provisioning token from file and delete it immediately after reading
   * @returns The provisioning token string or null if not found
   */
  public readAndDeleteToken(): string | null {
    try {
      if (!fs.existsSync(PROVISIONING_TOKEN_FILE)) {
        logger.info("No provisioning token file found");
        return null;
      }

      // Read the token
      const token = fs.readFileSync(PROVISIONING_TOKEN_FILE, "utf-8").trim();
      
      if (!token) {
        logger.warn("Provisioning token file is empty");
        // Delete empty file
        fs.unlinkSync(PROVISIONING_TOKEN_FILE);
        return null;
      }

      logger.info("Provisioning token read from file");
      
      // Delete the token file immediately
      fs.unlinkSync(PROVISIONING_TOKEN_FILE);
      logger.info("Provisioning token file deleted");

      return token;
    } catch (error) {
      logger.error("Failed to read or delete provisioning token:", error);
      return null;
    }
  }

  /**
   * Check if provisioning token file exists
   */
  public hasToken(): boolean {
    return fs.existsSync(PROVISIONING_TOKEN_FILE);
  }
}

export const provisioningTokenService = new ProvisioningTokenService();
