import fs from 'fs';
import { z } from 'zod';
import type { CredentialStorePort } from '../../ports/config.port';
import { MQTT_CREDENTIALS_FILE } from '../../infrastructure/paths';
import {
  atomicWriteFileSync,
  PersistentStateCorruptedError,
} from '../../infrastructure/file-persistence';

const credentialsSchema = z.object({
  username: z.string().min(1),
  password: z.string().min(1),
});

export class FileCredentialStore implements CredentialStorePort {
  constructor(private readonly filePath: string = MQTT_CREDENTIALS_FILE) {}

  getCredentials(): { username: string; password: string } | null {
    if (!fs.existsSync(this.filePath)) {
      return null;
    }
    fs.chmodSync(this.filePath, 0o600);
    try {
      const raw: unknown = JSON.parse(fs.readFileSync(this.filePath, 'utf8'));
      const parsed = credentialsSchema.safeParse(raw);
      if (!parsed.success) {
        throw new PersistentStateCorruptedError('MQTT credentials', this.filePath);
      }
      return parsed.data;
    } catch (error) {
      if (error instanceof PersistentStateCorruptedError) {
        throw error;
      }
      throw new PersistentStateCorruptedError('MQTT credentials', this.filePath, {
        cause: error,
      });
    }
  }

  saveCredentials(credentials: { username: string; password: string }): void {
    const parsed = credentialsSchema.parse(credentials);
    atomicWriteFileSync(this.filePath, JSON.stringify(parsed, null, 2), { mode: 0o600 });
  }

  isProvisioned(): boolean {
    return this.getCredentials() !== null;
  }
}
