import { z } from "zod";

const nonEmptyString = z.string().trim().min(1);

export const mqttCommandEnvelopeSchema = z.object({
  action: nonEmptyString,
  message_id: nonEmptyString,
  transaction_id: nonEmptyString,
  timestamp: nonEmptyString,
});

export const openCompartmentCommandSchema = mqttCommandEnvelopeSchema.extend({
  action: z.literal("open_compartment"),
  data: z.object({
    compartment_number: z.number().int().positive(),
  }),
});

export type OpenCompartmentCommand = z.infer<
  typeof openCompartmentCommandSchema
>;

export const applyConfigCommandSchema = mqttCommandEnvelopeSchema.extend({
  action: z.literal("apply_config"),
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
