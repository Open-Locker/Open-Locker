import type { CommandResponseBody, OutboundMqttPort, OutboundPublishOptions } from '../../ports/mqtt.port';
export declare class OutboundMqttAdapter implements OutboundMqttPort {
    private readonly publishRaw;
    private readonly responseTopic;
    private readonly nowIso;
    constructor(publishRaw: (topic: string, payload: string, options?: OutboundPublishOptions) => Promise<void>, responseTopic: string, nowIso?: () => string);
    publishJson(topic: string, body: Record<string, unknown>, options?: OutboundPublishOptions): Promise<void>;
    publishCommandResponse(body: CommandResponseBody): Promise<void>;
}
