"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.applyConfigCommandSchema = exports.openCompartmentCommandSchema = exports.mqttCommandEnvelopeSchema = void 0;
const zod_1 = require("zod");
const nonEmptyString = zod_1.z.string().trim().min(1);
exports.mqttCommandEnvelopeSchema = zod_1.z.object({
    action: nonEmptyString,
    message_id: nonEmptyString,
    transaction_id: nonEmptyString,
    timestamp: nonEmptyString,
});
exports.openCompartmentCommandSchema = exports.mqttCommandEnvelopeSchema.extend({
    action: zod_1.z.literal('open_compartment'),
    data: zod_1.z.object({
        compartment_number: zod_1.z.number().int().positive(),
    }),
});
exports.applyConfigCommandSchema = exports.mqttCommandEnvelopeSchema.extend({
    action: zod_1.z.literal('apply_config'),
    data: zod_1.z.object({
        config_hash: zod_1.z.string().regex(/^[a-f0-9]{64}$/i),
        heartbeat_interval_seconds: zod_1.z.number().int().positive(),
        compartments: zod_1.z.array(zod_1.z.object({
            compartment_number: zod_1.z.number().int().positive(),
            slaveId: zod_1.z.number().int().positive(),
            address: zod_1.z.number().int().nonnegative(),
        })),
    }),
});
