"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.InboundProtocolGuard = void 0;
class InboundProtocolGuard {
    dedup;
    constructor(dedup) {
        this.dedup = dedup;
    }
    allow(payload, options = {}) {
        const requiresTransactionId = options.requiresTransactionId ?? true;
        const blockDuplicateMessageIds = options.blockDuplicateMessageIds ?? true;
        const messageId = payload.message_id;
        if (typeof messageId !== 'string' || messageId.trim() === '') {
            return false;
        }
        if (requiresTransactionId) {
            const transactionId = payload.transaction_id;
            if (typeof transactionId !== 'string' || transactionId.trim() === '') {
                return false;
            }
        }
        if (!blockDuplicateMessageIds) {
            return true;
        }
        if (this.dedup.hasSeenMessageId(messageId)) {
            return false;
        }
        this.dedup.rememberMessageId(messageId);
        return true;
    }
}
exports.InboundProtocolGuard = InboundProtocolGuard;
