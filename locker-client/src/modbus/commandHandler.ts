import { logger } from "../helper/logger";
import { modbusService } from "../services/modbusService";

export class CommandHandler {
  private monitoringIntervals: Map<number, NodeJS.Timeout> = new Map();
  private readonly MONITORING_INTERVAL = 500; // 500ms polling interval
  private readonly COMPARTMENT_OPEN_DURATION = 200; // Duration to keep compartment open

  async handleOpenCompartment(compartmentID: number): Promise<void> {
    logger.info("Opening compartment:", compartmentID);

    try {
      await this.openCompartment(compartmentID);
      await this.startCoilMonitoring(compartmentID);
    } catch (error) {
      logger.error("Failed to execute command:", error);
      await this.reportError(error);
    }
  }

  private async openCompartment(compartmentID: number) {
    await modbusService.writeCoil(compartmentID, true);
    setTimeout(() => modbusService.writeCoil(compartmentID, false), this.COMPARTMENT_OPEN_DURATION);
  }

  private async startCoilMonitoring(compartmentID: number): Promise<void> {
    // Stop any existing monitoring for this compartment
    this.stopCoilMonitoring(compartmentID);

    logger.info(`Starting coil monitoring for compartment ${compartmentID}`);
    
    const monitorCoil = async () => {
      try {
        const coilStatus = await modbusService.readCoils(compartmentID, 1);
        const isOpen = coilStatus[0];
        
        logger.debug(`Compartment ${compartmentID} coil status: ${isOpen ? 'OPEN' : 'CLOSED'}`);

        // If compartment is closed, stop monitoring
        if (!isOpen) {
          logger.info(`Compartment ${compartmentID} is now closed. Stopping monitoring.`);
          this.stopCoilMonitoring(compartmentID);
        }
      } catch (error) {
        logger.error(`Error monitoring compartment ${compartmentID}:`, error);
        this.stopCoilMonitoring(compartmentID);
        await this.reportError(error);
      }
    };

    // Start monitoring immediately
    await monitorCoil();
    
    // Continue monitoring at regular intervals if compartment is still open
    if (!this.monitoringIntervals.has(compartmentID)) {
      const interval = setInterval(monitorCoil, this.MONITORING_INTERVAL);
      this.monitoringIntervals.set(compartmentID, interval);
    }
  }

  private stopCoilMonitoring(compartmentID: number): void {
    const interval = this.monitoringIntervals.get(compartmentID);
    if (interval) {
      clearInterval(interval);
      this.monitoringIntervals.delete(compartmentID);
      logger.info(`Stopped coil monitoring for compartment ${compartmentID}`);
    }
  }

  public stopAllMonitoring(): void {
    logger.info("Stopping all compartment monitoring");
    this.monitoringIntervals.forEach((interval, compartmentID) => {
      clearInterval(interval);
      logger.info(`Stopped monitoring for compartment ${compartmentID}`);
    });
    this.monitoringIntervals.clear();
  }

  private async reportError(error: any): Promise<void> {
    logger.error("Error reported:", error.message);
  }
}

export const commandHandler = new CommandHandler();
