import type { DedupStorePort } from "../../ports/mqtt.port";

export interface InboundGuardOptions {
  requiresTransactionId?: boolean;
  blockDuplicateMessageIds?: boolean;
}

export class InboundProtocolGuard {
  constructor(private readonly dedup: DedupStorePort) {}

  allow(
    payload: Record<string, unknown>,
    options: InboundGuardOptions = {},
  ): boolean {
    const requiresTransactionId = options.requiresTransactionId ?? true;
    const blockDuplicateMessageIds = options.blockDuplicateMessageIds ?? true;

    const messageId = payload.message_id;
    if (typeof messageId !== "string" || messageId.trim() === "") {
      return false;
    }

    if (requiresTransactionId) {
      const transactionId = payload.transaction_id;
      if (typeof transactionId !== "string" || transactionId.trim() === "") {
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
