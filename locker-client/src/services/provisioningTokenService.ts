import { logger } from "../helper/logger";

export class ProvisioningTokenService {
  /**
   * Read the provisioning token from environment variable
   * @returns The provisioning token string or null if not found
   */
  public readAndDeleteToken(): string | null {
    const token = process.env.PROVISIONING_TOKEN;
    
    if (!token || token.trim() === "") {
      logger.info("No provisioning token found in environment variable");
      return null;
    }

    logger.info("Provisioning token read from environment variable");
    return token.trim();
  }

  /**
   * Check if provisioning token exists in environment
   */
  public hasToken(): boolean {
    const token = process.env.PROVISIONING_TOKEN;
    return !!token && token.trim() !== "";
  }
}

export const provisioningTokenService = new ProvisioningTokenService();
