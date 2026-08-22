import { logger } from '../../infrastructure/logging';

/**
 * The simulator publishes fake state under a real locker UUID. Doing that
 * against production would corrupt live read models, so refuse by default.
 *
 * Both variables are read, not the first one that happens to be set. A
 * Laravel-shaped `.env` supplies `APP_ENV` while the Node runtime supplies
 * `NODE_ENV`, so `APP_ENV=local` alongside `NODE_ENV=production` is an ordinary
 * arrangement here — and coalescing on the first defined value would let it
 * through a guard that promises to check both.
 */
export function assertNotProduction(allowProduction: boolean): void {
  const environments = [process.env.APP_ENV, process.env.NODE_ENV]
    .map((value) => (value ?? '').trim().toLowerCase())
    .filter((value) => value !== '');

  const environment = environments.find((value) => value === 'production' || value === 'prod');

  if (environment === undefined) {
    return;
  }

  if (allowProduction) {
    logger.warn('Simulator starting against a production environment by explicit override', {
      environment,
    });
    return;
  }

  throw new Error(
    `Refusing to start the simulator with APP_ENV/NODE_ENV="${environment}". ` +
      'Pass --allow-production if this is genuinely intended.',
  );
}
