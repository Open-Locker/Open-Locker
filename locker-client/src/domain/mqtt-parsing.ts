import { z } from 'zod';
import {
  knownMQTTCommandSchema,
  provisioningResponseSchema,
  type KnownMQTTCommand,
  type ProvisioningResponse,
} from './mqtt-schemas';

export class MqttSchemaValidationError extends Error {
  constructor(
    message: string,
    public readonly validationErrors: Record<string, unknown>,
  ) {
    super(message);
    this.name = 'MqttSchemaValidationError';
  }
}

export function formatZodValidationError(error: z.ZodError): Record<string, unknown> {
  return z.flattenError(error);
}

export function parseProvisioningResponse(response: unknown): ProvisioningResponse {
  const parsed = provisioningResponseSchema.safeParse(response);
  if (!parsed.success) {
    throw new MqttSchemaValidationError(
      'Malformed provisioning response',
      formatZodValidationError(parsed.error),
    );
  }

  return parsed.data;
}

export function parseKnownMQTTCommand(cmd: unknown): KnownMQTTCommand | null {
  const result = knownMQTTCommandSchema.safeParse(cmd);
  return result.success ? result.data : null;
}
