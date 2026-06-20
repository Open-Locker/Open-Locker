import { randomBytes, randomUUID } from 'crypto';
import fs from 'fs';
import { z } from 'zod';
import type { CredentialStorePort } from '../ports/config.port';
import type { MessageTransportPort } from '../ports/mqtt.port';

const nonEmptyStringSchema = z.string().trim().min(1);

export const provisioningSuccessResponseSchema = z.object({
  message_id: nonEmptyStringSchema,
  status: z.literal('success'),
  timestamp: nonEmptyStringSchema,
  data: z.object({
    mqtt_user: nonEmptyStringSchema,
    mqtt_password: nonEmptyStringSchema,
  }),
});

export const provisioningErrorResponseSchema = z.object({
  message_id: nonEmptyStringSchema,
  status: z.literal('error'),
  timestamp: nonEmptyStringSchema,
  message: nonEmptyStringSchema,
});

export const provisioningResponseSchema = z.discriminatedUnion('status', [
  provisioningSuccessResponseSchema,
  provisioningErrorResponseSchema,
]);

export type ProvisioningResponse = z.infer<typeof provisioningResponseSchema>;

export function parseProvisioningResponse(response: unknown): ProvisioningResponse {
  const parsed = provisioningResponseSchema.safeParse(response);
  if (!parsed.success) {
    throw new Error('Malformed provisioning response');
  }

  return parsed.data;
}

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
        const response = parseProvisioningResponse(JSON.parse(payload.toString()));

        clearTimeout(timeout);

        if (response.status === 'success') {
          resolve({
            username: response.data.mqtt_user,
            password: response.data.mqtt_password,
          });
          return;
        }

        reject(new Error(response.message));
      } catch (error) {
        clearTimeout(timeout);
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
