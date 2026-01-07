import fs from "fs";
import { DATA_DIR, CONFIG_DIR } from "../config/paths";
import { logger } from "../helper/logger";

/**
 * Ensure required directories exist before starting the application
 */
export function ensureDirectories(): void {
  const directories = [DATA_DIR, CONFIG_DIR];

  directories.forEach((dir) => {
    if (!fs.existsSync(dir)) {
      try {
        fs.mkdirSync(dir, { recursive: true });
        logger.info(`Created directory: ${dir}`);
      } catch (error) {
        logger.error(`Failed to create directory ${dir}:`, error);
        throw error;
      }
    } else {
      logger.debug(`Directory exists: ${dir}`);
    }
  });
}
