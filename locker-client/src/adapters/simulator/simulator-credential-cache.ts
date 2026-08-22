import fs from 'fs';
import path from 'path';

interface CachedCredentials {
  username: string;
  password: string;
}

type CacheFileShape = Record<string, CachedCredentials>;

/**
 * Remembers MQTT credentials a simulated bank was issued, keyed by provisioning
 * token.
 *
 * The simulator was meant to simply provision by token on every start. It
 * cannot: `LockerBankAggregate::provision()` refuses once
 * `provisioned_at` is set, so a bank provisions exactly once for its lifetime
 * and every later run is rejected with "Locker bank is already provisioned".
 * Without a cache the simulator is single-use per bank, which defeats the point.
 *
 * The cache therefore stores what the backend will not re-issue. It lives beside
 * the scenario file rather than under `DATA_DIR`, so it can never be confused
 * with — or overwrite — a real device's credentials.
 */
export class SimulatorCredentialCache {
  private cache: CacheFileShape | null = null;

  constructor(private readonly filePath: string) {}

  static forScenario(scenarioFilePath: string): SimulatorCredentialCache {
    const override = process.env.SIMULATOR_CREDENTIALS_FILE?.trim();

    return new SimulatorCredentialCache(
      override ||
        path.join(path.dirname(path.resolve(scenarioFilePath)), '.simulator-credentials.json'),
    );
  }

  get location(): string {
    return this.filePath;
  }

  get(provisioningToken: string): CachedCredentials | null {
    const entry = this.read()[provisioningToken];

    if (!entry?.username?.trim() || !entry?.password) {
      return null;
    }

    return { ...entry };
  }

  set(provisioningToken: string, credentials: CachedCredentials): void {
    const cache = { ...this.read(), [provisioningToken]: { ...credentials } };

    fs.writeFileSync(this.filePath, `${JSON.stringify(cache, null, 2)}\n`, {
      encoding: 'utf8',
      // Credentials are secrets even in development.
      mode: 0o600,
    });

    this.cache = cache;
  }

  private read(): CacheFileShape {
    if (this.cache) {
      return this.cache;
    }

    if (!fs.existsSync(this.filePath)) {
      this.cache = {};

      return this.cache;
    }

    try {
      const raw = fs.readFileSync(this.filePath, 'utf8').trim();
      this.cache = raw ? (JSON.parse(raw) as CacheFileShape) : {};
    } catch {
      // A corrupt cache should cost a re-provision attempt, not a crash.
      this.cache = {};
    }

    return this.cache;
  }
}

/**
 * In-memory variant for tests and throwaway runs: nothing is written to disk, so
 * each run provisions afresh.
 */
export class EphemeralCredentialCache {
  private readonly entries = new Map<string, CachedCredentials>();

  get location(): string {
    return '(in memory)';
  }

  get(provisioningToken: string): CachedCredentials | null {
    const entry = this.entries.get(provisioningToken);

    return entry ? { ...entry } : null;
  }

  set(provisioningToken: string, credentials: CachedCredentials): void {
    this.entries.set(provisioningToken, { ...credentials });
  }
}

export type CredentialCache = Pick<SimulatorCredentialCache, 'get' | 'set' | 'location'>;
