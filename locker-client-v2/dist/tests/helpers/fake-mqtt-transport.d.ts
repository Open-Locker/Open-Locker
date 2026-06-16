import type { MessageTransportPort, MqttConnectionState, MqttTransportSettings, OutboundPublishOptions } from "../../src/ports/mqtt.port";
export declare class FakeMqttTransport implements MessageTransportPort {
    readonly published: Array<{
        topic: string;
        payload: string;
    }>;
    readonly subscriptions: string[];
    private state;
    private messageHandler;
    private readonly transport;
    constructor(transport?: MqttTransportSettings);
    getConnectionState(): MqttConnectionState;
    getTransportSettings(): MqttTransportSettings;
    connect(): Promise<void>;
    disconnect(): Promise<void>;
    simulateBrokerDrop(): void;
    simulateBrokerRestore(): void;
    subscribe(topic: string): Promise<void>;
    publish(topic: string, payload: string, _options?: OutboundPublishOptions): Promise<void>;
    onMessage(handler: (topic: string, payload: Buffer) => void): void;
    emitMessage(topic: string, payload: Record<string, unknown>): void;
}
