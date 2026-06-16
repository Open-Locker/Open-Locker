import { z } from "zod";
export declare const mqttCommandEnvelopeSchema: z.ZodObject<{
    action: z.ZodString;
    message_id: z.ZodString;
    transaction_id: z.ZodString;
    timestamp: z.ZodString;
}, z.core.$strip>;
export declare const openCompartmentCommandSchema: z.ZodObject<{
    message_id: z.ZodString;
    transaction_id: z.ZodString;
    timestamp: z.ZodString;
    action: z.ZodLiteral<"open_compartment">;
    data: z.ZodObject<{
        compartment_number: z.ZodNumber;
    }, z.core.$strip>;
}, z.core.$strip>;
export type OpenCompartmentCommand = z.infer<typeof openCompartmentCommandSchema>;
export declare const applyConfigCommandSchema: z.ZodObject<{
    message_id: z.ZodString;
    transaction_id: z.ZodString;
    timestamp: z.ZodString;
    action: z.ZodLiteral<"apply_config">;
    data: z.ZodObject<{
        config_hash: z.ZodString;
        heartbeat_interval_seconds: z.ZodNumber;
        compartments: z.ZodArray<z.ZodObject<{
            compartment_number: z.ZodNumber;
            slaveId: z.ZodNumber;
            address: z.ZodNumber;
        }, z.core.$strip>>;
    }, z.core.$strip>;
}, z.core.$strip>;
export type ApplyConfigCommand = z.infer<typeof applyConfigCommandSchema>;
