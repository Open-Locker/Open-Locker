import fs from 'fs';
import { z } from 'zod';
import type { CredentialStorePort, DeviceCredentials } from '../../ports/config.port';
import { MQTT_CREDENTIALS_FILE } from '../../infrastructure/paths';
import {
  atomicWriteFileSync,
  PersistentStateCorruptedError,
  readPrivateFileSync,
} from '../../infrastructure/file-persistence';

// `lockerUuid` is optional on read and defaults to the username: a file written
// before per-provisioning identities holds a username that *was* the locker uuid,
// so an existing installation keeps working without being rewritten.
const credentialsSchema = z
  .object({
    username: z.string().min(1),
    password: z.string().min(1),
    lockerUuid: z.string().min(1).optional(),
  })
  .transform((c) => ({ ...c, lockerUuid: c.lockerUuid ?? c.username }));

export class FileCredentialStore implements CredentialStorePort {
  constructor(private readonly filePath: string = MQTT_CREDENTIALS_FILE) {}

  getCredentials(): DeviceCredentials | null {
    if (!fs.existsSync(this.filePath)) {
      return null;
    }
    try {
      const raw: unknown = JSON.parse(readPrivateFileSync(this.filePath).toString('utf8'));
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

  saveCredentials(credentials: DeviceCredentials): void {
    const parsed = credentialsSchema.parse(credentials);
    atomicWriteFileSync(this.filePath, JSON.stringify(parsed, null, 2), { mode: 0o600 });
  }

  isProvisioned(): boolean {
    return this.getCredentials() !== null;
  }
}
