import type { z } from 'zod';
import { InboundProtocolGuard } from './inbound-protocol-guard';
import type { OutboundMqttPort } from '../../ports/mqtt.port';
export interface CommandContext {
    lockerUuid: string;
}
export interface InboundCommandHandler<TPayload> {
    readonly action: string;
    readonly schema: z.ZodType<TPayload>;
    requiresTransactionId(): boolean;
    handle(context: CommandContext, payload: TPayload): Promise<void>;
}
export declare class CommandDispatcher {
    private readonly guard;
    private readonly outbound;
    private readonly handlers;
    constructor(guard: InboundProtocolGuard, outbound: OutboundMqttPort);
    register(handler: InboundCommandHandler<unknown>): void;
    dispatch(topic: string, rawMessage: string): Promise<void>;
}
