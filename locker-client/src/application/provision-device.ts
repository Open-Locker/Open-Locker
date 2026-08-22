import { randomBytes, randomUUID } from 'crypto';
import fs from 'fs';
import { provisioningRequestSchema } from '../domain/mqtt-schemas';
import { MqttSchemaValidationError, parseProvisioningResponse } from '../domain/mqtt-parsing';
import type { CredentialStorePort, DeviceCredentials } from '../ports/config.port';
import type { MessageTransportPort } from '../ports/mqtt.port';
import { logger } from '../infrastructure/logging';
import {
  atomicWriteFileSync,
  PersistentStateCorruptedError,
  readPrivateFileSync,
} from '../infrastructure/file-persistence';

export const DEFAULT_MQTT_BROKER_URL = 'mqtt://open-locker.cloud';

export function getOrCreateClientId(clientIdFilePath: string): string {
  const configuredClientId = process.env.MQTT_CLIENT_ID?.trim();
  if (configuredClientId) {
    validateClientId(configuredClientId, 'MQTT_CLIENT_ID');
    return configuredClientId;
  }

  if (fs.existsSync(clientIdFilePath)) {
    const existing = readPrivateFileSync(clientIdFilePath).toString('utf8').trim();
    try {
      validateClientId(existing, clientIdFilePath);
    } catch (error) {
      throw new PersistentStateCorruptedError('MQTT client ID', clientIdFilePath, {
        cause: error,
      });
    }
    return existing;
  }

  const clientId = `locker-client-${randomBytes(4).toString('hex')}`;
  atomicWriteFileSync(clientIdFilePath, clientId, { mode: 0o600 });
  return clientId;
}

function validateClientId(clientId: string, source: string): void {
  if (!/^[A-Za-z0-9:_-]{1,128}$/.test(clientId)) {
    throw new Error(`Invalid MQTT client ID in ${source}`);
  }
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
  await options.transport.disconnect();
}

function waitForProvisioningReply(
  transport: MessageTransportPort,
  replyTopic: string,
  registerTopic: string,
  clientId: string,
): Promise<DeviceCredentials> {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      reject(new Error('Provisioning timed out'));
    }, 30_000);

    transport.onMessage((topic, payload) => {
      if (topic !== replyTopic) {
        return;
      }

      try {
        const response = parseProvisioningResponse(JSON.parse(payload.toString()));

        clearTimeout(timeout);

        if (response.status === 'success') {
          resolve({
            username: response.data.mqtt_user,
            password: response.data.mqtt_password,
            lockerUuid: response.data.locker_uuid ?? response.data.mqtt_user,
          });
          return;
        }

        reject(new Error(response.message));
      } catch (error) {
        clearTimeout(timeout);
        if (error instanceof MqttSchemaValidationError) {
          logger.error('Malformed provisioning response', {
            topic: replyTopic,
            validationErrors: error.validationErrors,
          });
        }
        reject(error);
      }
    });

    const request = provisioningRequestSchema.parse({
      client_id: clientId,
      message_id: randomUUID(),
      timestamp: new Date().toISOString(),
    });

    void transport.publish(registerTopic, JSON.stringify(request), { qos: 1 }).catch(reject);
  });
}
