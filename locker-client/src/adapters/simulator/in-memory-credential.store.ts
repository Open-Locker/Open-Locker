import type { CredentialStorePort } from '../../ports/config.port';

/**
 * Credentials for one simulated device, held only for the lifetime of the run.
 *
 * The production `FileCredentialStore` resolves its path from a module-level
 * constant, so it is process-global and cannot back several devices at once
 * (ADR-0027). A simulator device provisions by token at startup and has nothing
 * worth persisting, so keeping credentials in memory both enables fleet mode and
 * guarantees the simulator never writes over a real device's `/data` files.
 */
export class InMemoryCredentialStore implements CredentialStorePort {
  private credentials: { username: string; password: string } | null = null;

  getCredentials(): { username: string; password: string } | null {
    return this.credentials;
  }

  saveCredentials(credentials: { username: string; password: string }): void {
    this.credentials = { ...credentials };
  }

  isProvisioned(): boolean {
    return this.credentials !== null;
  }
}
