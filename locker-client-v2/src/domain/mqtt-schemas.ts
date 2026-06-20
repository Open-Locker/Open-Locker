import { z } from 'zod';

export const nonEmptyString = z.string().trim().min(1);

export const mqttCommandEnvelopeSchema = z.object({
  action: nonEmptyString,
  message_id: nonEmptyString,
  transaction_id: nonEmptyString,
  timestamp: nonEmptyString,
});

export const openCompartmentCommandSchema = mqttCommandEnvelopeSchema.extend({
  action: z.literal('open_compartment'),
  data: z.object({
    compartment_number: z.number().int().positive(),
  }),
});

export type OpenCompartmentCommand = z.infer<typeof openCompartmentCommandSchema>;

export const applyConfigCommandSchema = mqttCommandEnvelopeSchema.extend({
  action: z.literal('apply_config'),
  data: z.object({
    config_hash: z.string().regex(/^[a-f0-9]{64}$/i),
    heartbeat_interval_seconds: z.number().int().positive(),
    compartments: z.array(
      z.object({
        compartment_number: z.number().int().positive(),
        slaveId: z.number().int().positive(),
        address: z.number().int().nonnegative(),
      }),
    ),
  }),
});

export type ApplyConfigCommand = z.infer<typeof applyConfigCommandSchema>;

export const knownMQTTCommandSchema = z.discriminatedUnion('action', [
  openCompartmentCommandSchema,
  applyConfigCommandSchema,
]);

export type KnownMQTTCommand = z.infer<typeof knownMQTTCommandSchema>;

export const provisioningRequestSchema = z.object({
  message_id: nonEmptyString,
  client_id: nonEmptyString,
  timestamp: nonEmptyString,
});

export type ProvisioningRequest = z.infer<typeof provisioningRequestSchema>;

export const provisioningSuccessResponseSchema = z.object({
  message_id: nonEmptyString,
  status: z.literal('success'),
  timestamp: nonEmptyString,
  data: z.object({
    mqtt_user: nonEmptyString,
    mqtt_password: nonEmptyString,
  }),
});

export const provisioningErrorResponseSchema = z.object({
  message_id: nonEmptyString,
  status: z.literal('error'),
  timestamp: nonEmptyString,
  message: nonEmptyString,
});

export const provisioningResponseSchema = z.discriminatedUnion('status', [
  provisioningSuccessResponseSchema,
  provisioningErrorResponseSchema,
]);

export type ProvisioningResponse = z.infer<typeof provisioningResponseSchema>;
