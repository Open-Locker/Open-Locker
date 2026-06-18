"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.CommandDispatcher = void 0;
const errors_1 = require("../../domain/errors");
class CommandDispatcher {
    guard;
    outbound;
    dedup;
    handlers = new Map();
    constructor(guard, outbound, dedup) {
        this.guard = guard;
        this.outbound = outbound;
        this.dedup = dedup;
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
        const command = parsed.data;
        if (handler.requiresTransactionId()) {
            const dedupResult = await this.guardTransactionExecution(action, command.transaction_id);
            if (dedupResult === 'duplicate_completed') {
                return;
            }
            if (dedupResult === 'duplicate_in_progress') {
                return;
            }
        }
        try {
            await handler.handle({ lockerUuid }, parsed.data);
            if (handler.requiresTransactionId()) {
                this.dedup.markCommandCompleted(command.transaction_id, action);
            }
        }
        catch (error) {
            if (handler.requiresTransactionId()) {
                this.dedup.markCommandCompleted(command.transaction_id, action);
            }
            await this.outbound.publishCommandResponse({
                type: 'command_response',
                action,
                result: 'error',
                transaction_id: command.transaction_id,
                error_code: (0, errors_1.mapErrorToMqttCode)(error),
                message: error instanceof Error ? error.message : 'Unknown error',
            });
        }
    }
    async guardTransactionExecution(action, transactionId) {
        const existing = this.dedup.getCommandRecord(transactionId);
        if (existing?.status === 'completed') {
            return 'duplicate_completed';
        }
        if (existing?.status === 'in_progress') {
            return 'duplicate_in_progress';
        }
        this.dedup.markCommandInProgress(transactionId, action);
        return 'ready';
    }
}
exports.CommandDispatcher = CommandDispatcher;
function extractLockerUuid(topic) {
    const parts = topic.split('/');
    return parts[1] ?? '';
}
