import type { DedupStorePort } from '../../ports/mqtt.port';

export interface InboundGuardOptions {
  requiresTransactionId?: boolean;
  blockDuplicateMessageIds?: boolean;
}

export type InboundGuardRejectReason =
  | 'missing_message_id'
  | 'missing_transaction_id'
  | 'duplicate_message_id';

export type InboundGuardResult = { ok: true } | { ok: false; reason: InboundGuardRejectReason };

export class InboundProtocolGuard {
  constructor(private readonly dedup: DedupStorePort) {}

  allow(payload: Record<string, unknown>, options: InboundGuardOptions = {}): InboundGuardResult {
    const requiresTransactionId = options.requiresTransactionId ?? true;
    const blockDuplicateMessageIds = options.blockDuplicateMessageIds ?? true;

    const messageId = payload.message_id;
    if (typeof messageId !== 'string' || messageId.trim() === '') {
      return { ok: false, reason: 'missing_message_id' };
    }

    if (requiresTransactionId) {
      const transactionId = payload.transaction_id;
      if (typeof transactionId !== 'string' || transactionId.trim() === '') {
        return { ok: false, reason: 'missing_transaction_id' };
      }
    }

    if (!blockDuplicateMessageIds) {
      return { ok: true };
    }

    if (this.dedup.hasSeenMessageId(messageId)) {
      return { ok: false, reason: 'duplicate_message_id' };
    }

    this.dedup.rememberMessageId(messageId);
    return { ok: true };
  }
}
