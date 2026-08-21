import 'dotenv/config';
import { createApp } from './bootstrap/createApp';
import { logger } from './infrastructure/logging';

async function main(): Promise<void> {
  const app = await createApp();

  // A second signal must not start a second shutdown: two sequences racing
  // would disconnect the transport under the one still publishing responses.
  let stopping: Promise<void> | null = null;

  const shutdown = async (signal: string): Promise<void> => {
    if (stopping) {
      logger.info(`Received ${signal} while already shutting down; ignoring.`);
      await stopping;

      return;
    }

    logger.info(`Received ${signal}, shutting down...`);
    stopping = app.shutdown();

    // Exit either way. A shutdown that threw has already given up whatever step
    // failed, and staying alive holding the Modbus port helps nobody.
    try {
      await stopping;
      process.exit(0);
    } catch (error: unknown) {
      logger.error('Shutdown failed', {
        error: error instanceof Error ? error.message : 'Unknown shutdown error',
      });
      process.exit(1);
    }
  };

  process.on('SIGINT', () => void shutdown('SIGINT'));
  process.on('SIGTERM', () => void shutdown('SIGTERM'));

  // A process driving physical locks should not keep running in an unknown
  // state. Exiting non-zero hands it back to Docker's restart policy, which
  // starts clean and reconciles door state from the hardware.
  process.on('uncaughtException', (error: Error) => {
    logger.error('Uncaught exception; exiting', { error: error.message });
    process.exit(1);
  });

  process.on('unhandledRejection', (reason: unknown) => {
    logger.error('Unhandled promise rejection; exiting', {
      error: reason instanceof Error ? reason.message : String(reason),
    });
    process.exit(1);
  });
}

main().catch((error) => {
  logger.error('Fatal startup error', error);
  process.exit(1);
});
