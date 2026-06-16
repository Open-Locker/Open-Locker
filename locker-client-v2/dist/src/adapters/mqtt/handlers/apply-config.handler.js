"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createApplyConfigHandler = createApplyConfigHandler;
const mqtt_schemas_1 = require("../../../domain/mqtt-schemas");
function createApplyConfigHandler(deps) {
    return {
        action: "apply_config",
        schema: mqtt_schemas_1.applyConfigCommandSchema,
        requiresTransactionId: () => true,
        async handle(_ctx, payload) {
            const command = mqtt_schemas_1.applyConfigCommandSchema.parse(payload);
            const result = await deps.applyConfig.execute(command);
            await deps.outbound.publishCommandResponse({
                type: "command_response",
                action: command.action,
                result: "success",
                transaction_id: command.transaction_id,
                applied_config_hash: result.appliedConfigHash,
                message: result.message,
            });
        },
    };
}
