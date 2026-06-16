"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createOpenCompartmentHandler = createOpenCompartmentHandler;
const mqtt_schemas_1 = require("../../../domain/mqtt-schemas");
function createOpenCompartmentHandler(deps) {
    return {
        action: "open_compartment",
        schema: mqtt_schemas_1.openCompartmentCommandSchema,
        requiresTransactionId: () => true,
        async handle(_ctx, payload) {
            const command = mqtt_schemas_1.openCompartmentCommandSchema.parse(payload);
            const existing = deps.dedup.getCommandRecord(command.transaction_id);
            if (existing?.status === "completed") {
                await deps.outbound.publishCommandResponse({
                    type: "command_response",
                    action: command.action,
                    result: "success",
                    transaction_id: command.transaction_id,
                    message: "Duplicate command ignored (already completed).",
                });
                return;
            }
            if (existing?.status === "in_progress") {
                return;
            }
            deps.dedup.markCommandInProgress(command.transaction_id, command.action);
            await deps.openCompartment.execute(command.data.compartment_number);
            await deps.pollSnapshot.pollAndPublish(true);
            deps.dedup.markCommandCompleted(command.transaction_id, command.action);
            await deps.outbound.publishCommandResponse({
                type: "command_response",
                action: command.action,
                result: "success",
                transaction_id: command.transaction_id,
                message: "Compartment opened.",
            });
        },
    };
}
