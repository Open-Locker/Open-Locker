export interface MqttEnvelope {
    message_id: string;
    timestamp: string;
}
export declare function createEnvelope(body: Record<string, unknown>, nowIso?: () => string): Record<string, unknown>;
export declare function serializeOutboundPayload(body: Record<string, unknown>, nowIso?: () => string): string;
