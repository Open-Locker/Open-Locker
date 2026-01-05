import fs from "fs";
import path from "path";
import { logger } from "../helper/logger";

const PROVISIONING_STATE_FILE = path.join(process.cwd(), ".provisioning-state");

export class ProvisioningService {
  private isProvisioned: boolean = false;

  constructor() {
    this.loadProvisioningState();
  }

  private loadProvisioningState(): void {
    try {
      if (fs.existsSync(PROVISIONING_STATE_FILE)) {
        const state = fs.readFileSync(PROVISIONING_STATE_FILE, "utf-8").trim();
        this.isProvisioned = state === "provisioned";
        logger.info(`Provisioning state loaded: ${this.isProvisioned ? "PROVISIONED" : "NOT PROVISIONED"}`);
      } else {
        logger.info("No provisioning state found. Locker is not provisioned.");
        this.isProvisioned = false;
      }
    } catch (error) {
      logger.error("Failed to load provisioning state:", error);
      this.isProvisioned = false;
    }
  }

  public getProvisioningState(): boolean {
    return this.isProvisioned;
  }

  public markAsProvisioned(): void {
    try {
      fs.writeFileSync(PROVISIONING_STATE_FILE, "provisioned", "utf-8");
      this.isProvisioned = true;
      logger.info("Locker marked as provisioned");
    } catch (error) {
      logger.error("Failed to save provisioning state:", error);
      throw error;
    }
  }

  public resetProvisioningState(): void {
    try {
      if (fs.existsSync(PROVISIONING_STATE_FILE)) {
        fs.unlinkSync(PROVISIONING_STATE_FILE);
      }
      this.isProvisioned = false;
      logger.info("Provisioning state reset");
    } catch (error) {
      logger.error("Failed to reset provisioning state:", error);
      throw error;
    }
  }
}

export const provisioningService = new ProvisioningService();
