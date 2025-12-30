import { mqttConfig } from "../config/mqtt";
import { logger } from "../helper/logger";
import { credentialsService } from "./credentialsService";
import { mqttService } from "./mqttService";

class HeartbeatService {
  private intervalId: NodeJS.Timeout | null = null;
  private startTime: number = Date.now();

  /**
   * Start sending heartbeat messages
   */
  start(): void {
    if (this.intervalId) {
      logger.warn("Heartbeat service is already running");
      return;
    }

    logger.info(`Starting heartbeat service with interval: ${mqttConfig.heartbeatInterval / 1000}s`);
    
    // Reset start time
    this.startTime = Date.now();

    // Send first heartbeat immediately
    this.sendHeartbeat();

    // Then send at regular intervals
    this.intervalId = setInterval(() => {
      this.sendHeartbeat();
    }, mqttConfig.heartbeatInterval);
  }

  /**
   * Stop sending heartbeat messages
   */
  stop(): void {
    if (this.intervalId) {
      clearInterval(this.intervalId);
      this.intervalId = null;
      logger.info("Heartbeat service stopped");
    }
  }

  /**
   * Send a heartbeat message
   */
  private async sendHeartbeat(): Promise<void> {
    try {
      const credentials = credentialsService.getCredentials();
      
      if (!credentials?.username) {
        logger.error("Cannot send heartbeat: No username available");
        return;
      }

      const uuid = credentials.username;
      const topic = `locker/${uuid}/state`;
      
      const uptimeSeconds = Math.floor((Date.now() - this.startTime) / 1000);
      
      const payload = {
        event: "heartbeat",
        data: {
          timestamp: new Date().toISOString(),
          uptime_seconds: uptimeSeconds,
        },
      };

      await mqttService.publish(topic, payload);
      logger.debug(`Heartbeat sent to ${topic}`, { uptime_seconds: uptimeSeconds });
    } catch (error) {
      logger.error("Failed to send heartbeat:", error);
    }
  }

  /**
   * Check if heartbeat service is running
   */
  isRunning(): boolean {
    return this.intervalId !== null;
  }
}

export const heartbeatService = new HeartbeatService();
