"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.CommandDispatcher = void 0;
const errors_1 = require("../../domain/errors");
class CommandDispatcher {
    guard;
    outbound;
    handlers = new Map();
    constructor(guard, outbound) {
        this.guard = guard;
        this.outbound = outbound;
    }
    register(handler) {
        this.handlers.set(handler.action, handler);
    }
    async dispatch(topic, rawMessage) {
        let payload;
        try {
            payload = JSON.parse(rawMessage);
        }
        catch {
            return;
        }
        const action = payload.action;
        if (typeof action !== 'string') {
            return;
        }
        const handler = this.handlers.get(action);
        if (!handler) {
            return;
        }
        if (!this.guard.allow(payload, {
            requiresTransactionId: handler.requiresTransactionId(),
        })) {
            return;
        }
        const parsed = handler.schema.safeParse(payload);
        if (!parsed.success) {
            await this.outbound.publishCommandResponse({
                type: 'command_response',
                action,
                result: 'error',
                transaction_id: typeof payload.transaction_id === 'string' ? payload.transaction_id : 'unknown',
                error_code: 'INVALID_COMMAND',
                message: 'Command validation failed',
            });
            return;
        }
        const lockerUuid = extractLockerUuid(topic);
        try {
            await handler.handle({ lockerUuid }, parsed.data);
        }
        catch (error) {
            await this.outbound.publishCommandResponse({
                type: 'command_response',
                action,
                result: 'error',
                transaction_id: parsed.data.transaction_id,
                error_code: (0, errors_1.mapErrorToMqttCode)(error),
                message: error instanceof Error ? error.message : 'Unknown error',
            });
        }
    }
}
exports.CommandDispatcher = CommandDispatcher;
function extractLockerUuid(topic) {
    const parts = topic.split('/');
    return parts[1] ?? '';
}
