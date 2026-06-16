import type { DedupStorePort } from "../../ports/mqtt.port";
export interface InboundGuardOptions {
    requiresTransactionId?: boolean;
    blockDuplicateMessageIds?: boolean;
}
export declare class InboundProtocolGuard {
    private readonly dedup;
    constructor(dedup: DedupStorePort);
    allow(payload: Record<string, unknown>, options?: InboundGuardOptions): boolean;
}
