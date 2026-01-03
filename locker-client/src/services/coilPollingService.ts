import { logger } from "../helper/logger";
import { modbusService } from "./modbusService";

class CoilPollingService {
  private intervalId: NodeJS.Timeout | null = null;
  private pollingInterval: number = 5000; // 5 seconds
  private primaryClient: string = "default";

  /**
   * Start polling coils
   */
  start(): void {
    if (this.intervalId) {
      logger.warn("Coil polling service is already running");
      return;
    }

    // Get the first available client for monitoring
    const clientIds = modbusService.getClientIds();
    this.primaryClient = clientIds[0] || "default";

    logger.info(`Starting coil polling service with interval: ${this.pollingInterval / 1000}s for client: ${this.primaryClient}`);

    // Start polling at regular intervals
    this.intervalId = setInterval(() => {
      this.pollCoils();
    }, this.pollingInterval);

    // Poll immediately on start
    this.pollCoils();
  }

  /**
   * Stop polling coils
   */
  stop(): void {
    if (this.intervalId) {
      clearInterval(this.intervalId);
      this.intervalId = null;
      logger.info("Coil polling service stopped");
    }
  }

  /**
   * Poll coils once
   */
  private async pollCoils(): Promise<void> {
    try {
      const coils = await modbusService.readCoils(0x0000, 1, this.primaryClient);
      logger.debug(`[${this.primaryClient}] Coil status 0:`, coils);
    } catch (error) {
      logger.error("Error reading coil status:", error);
    }
  }

  /**
   * Set the polling interval
   */
  setPollingInterval(intervalMs: number): void {
    this.pollingInterval = intervalMs;
    
    // Restart if already running
    if (this.intervalId) {
      this.stop();
      this.start();
    }
  }
}

export const coilPollingService = new CoilPollingService();
