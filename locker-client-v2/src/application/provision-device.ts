import { randomBytes, randomUUID } from 'crypto';
import fs from 'fs';
import type { CredentialStorePort } from '../ports/config.port';
import type { MessageTransportPort } from '../ports/mqtt.port';

export const DEFAULT_MQTT_BROKER_URL = 'mqtt://open-locker.cloud';

export function getOrCreateClientId(clientIdFilePath: string): string {
  if (process.env.MQTT_CLIENT_ID) {
    return process.env.MQTT_CLIENT_ID;
  }

  if (fs.existsSync(clientIdFilePath)) {
    const existing = fs.readFileSync(clientIdFilePath, 'utf8').trim();
    if (existing) {
      return existing;
    }
  }

  const clientId = `locker-client-${randomBytes(4).toString('hex')}`;
  fs.writeFileSync(clientIdFilePath, clientId, 'utf8');
  return clientId;
}

export function getRequiredProvisioningDefaults(): {
  defaultUsername: string;
  defaultPassword: string;
} {
  const username = process.env.MQTT_DEFAULT_USERNAME?.trim();
  const password = process.env.MQTT_DEFAULT_PASSWORD?.trim();
  if (!username || !password) {
    throw new Error('Missing MQTT_DEFAULT_USERNAME or MQTT_DEFAULT_PASSWORD environment variables');
  }
  return { defaultUsername: username, defaultPassword: password };
}

export async function provisionDevice(options: {
  transport: MessageTransportPort;
  brokerUrl: string;
  clientId: string;
  provisioningToken: string;
  credentialStore: CredentialStorePort;
}): Promise<void> {
  const { defaultUsername, defaultPassword } = getRequiredProvisioningDefaults();
  const replyTopic = `locker/provisioning/reply/${options.clientId}`;
  const registerTopic = `locker/register/${options.provisioningToken}`;

  await options.transport.connect(options.brokerUrl, {
    username: defaultUsername,
    password: defaultPassword,
    clientId: options.clientId,
  });

  await options.transport.subscribe(replyTopic);

  const credentials = await waitForProvisioningReply(
    options.transport,
    replyTopic,
    registerTopic,
    options.clientId,
  );

  options.credentialStore.saveCredentials(credentials);
  options.credentialStore.markProvisioned();
  await options.transport.disconnect();
}

function waitForProvisioningReply(
  transport: MessageTransportPort,
  replyTopic: string,
  registerTopic: string,
  clientId: string,
): Promise<{ username: string; password: string }> {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      reject(new Error('Provisioning timed out'));
    }, 30_000);

    transport.onMessage((topic, payload) => {
      if (topic !== replyTopic) {
        return;
      }

      try {
        const message = JSON.parse(payload.toString()) as {
          result?: string;
          username?: string;
          password?: string;
          message?: string;
        };

        clearTimeout(timeout);

        if (message.result === 'success' && message.username && message.password) {
          resolve({ username: message.username, password: message.password });
          return;
        }

        reject(new Error(message.message ?? 'Provisioning failed'));
      } catch (error) {
        reject(error);
      }
    });

    void transport
      .publish(
        registerTopic,
        JSON.stringify({
          client_id: clientId,
          message_id: randomUUID(),
          timestamp: new Date().toISOString(),
        }),
        { qos: 1 },
      )
      .catch(reject);
  });
}
