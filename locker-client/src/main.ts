import 'dotenv/config';
import { createApp } from './bootstrap/createApp';
import { logger } from './infrastructure/logging';

async function main(): Promise<void> {
  const app = await createApp();

  const shutdown = async (signal: string) => {
    logger.info(`Received ${signal}, shutting down...`);
    await app.shutdown();
    process.exit(0);
  };

  process.on('SIGINT', () => void shutdown('SIGINT'));
  process.on('SIGTERM', () => void shutdown('SIGTERM'));
}

main().catch((error) => {
  logger.error('Fatal startup error', error);
  process.exit(1);
});
