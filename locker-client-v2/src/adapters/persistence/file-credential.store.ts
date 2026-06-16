import fs from 'fs';
import { z } from 'zod';
import type { CredentialStorePort } from '../../ports/config.port';
import { MQTT_CREDENTIALS_FILE, PROVISIONING_STATE_FILE } from '../../infrastructure/paths';

const credentialsSchema = z.object({
  username: z.string().min(1),
  password: z.string().min(1),
});

export class FileCredentialStore implements CredentialStorePort {
  getCredentials(): { username: string; password: string } | null {
    if (!fs.existsSync(MQTT_CREDENTIALS_FILE)) {
      return null;
    }
    const raw = JSON.parse(fs.readFileSync(MQTT_CREDENTIALS_FILE, 'utf8'));
    const parsed = credentialsSchema.safeParse(raw);
    return parsed.success ? parsed.data : null;
  }

  saveCredentials(credentials: { username: string; password: string }): void {
    fs.writeFileSync(MQTT_CREDENTIALS_FILE, JSON.stringify(credentials, null, 2), 'utf8');
  }

  isProvisioned(): boolean {
    return fs.existsSync(PROVISIONING_STATE_FILE);
  }

  markProvisioned(): void {
    fs.writeFileSync(PROVISIONING_STATE_FILE, 'provisioned', 'utf8');
  }
}
