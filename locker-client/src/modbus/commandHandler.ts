import { modbusConfig } from "../config/modbus";
import { logger } from "../helper/logger";
import { modbusService } from "../services/modbusService";
import { mqttService } from "../services/mqttService";

interface LockerCommand {
  action: "unlock" | "lock" | "status";
  lockerId?: string;
  duration?: number; // Auto-lock duration in seconds
}

export class CommandHandler {
  async handleCommand(command: LockerCommand): Promise<void> {
    logger.info("Received command:", command);

    try {
      switch (command.action) {
        case "unlock":
          await this.unlockLocker(command.duration);
          break;
        case "lock":
          await this.lockLocker();
          break;
        case "status":
          await this.getLockerStatus();
          break;
        default:
          logger.warn("Unknown command action:", command.action);
      }
    } catch (error) {
      logger.error("Failed to execute command:", error);
      await this.reportError(error);
    }
  }

  private async unlockLocker(duration?: number): Promise<void> {
    logger.info("Unlocking locker...");

    // Write to Modbus coil to unlock
    await modbusService.writeCoil(modbusConfig.addresses.lockControl, true);

    // Report status change
    await mqttService.publishStatus({
      status: "unlocked",
      timestamp: new Date().toISOString(),
      action: "unlock",
    });

    // Auto-lock after duration if specified
    if (duration && duration > 0) {
      logger.info(`Auto-lock scheduled in ${duration} seconds`);
      setTimeout(async () => {
        try {
          await this.lockLocker();
        } catch (error) {
          logger.error("Auto-lock failed:", error);
        }
      }, duration * 1000);
    }
  }

  private async lockLocker(): Promise<void> {
    logger.info("Locking locker...");

    // Write to Modbus coil to lock
    await modbusService.writeCoil(modbusConfig.addresses.lockControl, false);

    // Report status change
    await mqttService.publishStatus({
      status: "locked",
      timestamp: new Date().toISOString(),
      action: "lock",
    });
  }

  private async getLockerStatus(): Promise<void> {
    logger.info("Reading locker status...");

    // Read lock status and door sensor
    const lockStatus = await modbusService.readCoils(
      modbusConfig.addresses.lockStatus,
      1
    );
    const doorStatus = await modbusService.readCoils(
      modbusConfig.addresses.doorSensor,
      1
    );

    const status = {
      lockStatus: lockStatus[0] ? "locked" : "unlocked",
      doorStatus: doorStatus[0] ? "closed" : "open",
      timestamp: new Date().toISOString(),
      action: "status_check",
    };

    await mqttService.publishStatus(status);
    logger.info("Status reported:", status);
  }

  private async reportError(error: any): Promise<void> {
    await mqttService.publishStatus({
      status: "error",
      error: error.message,
      timestamp: new Date().toISOString(),
      action: "error_report",
    });
  }
}

export const commandHandler = new CommandHandler();
