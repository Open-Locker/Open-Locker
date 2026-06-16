import type { MessageTransportPort, MqttConnectionState, MqttTransportSettings, OutboundPublishOptions } from "../../ports/mqtt.port";
export declare class MqttTransportAdapter implements MessageTransportPort {
    private client;
    private connectionState;
    private intentionalShutdown;
    private reconnectExhausted;
    private reconnectAttempts;
    private connectInFlight;
    private messageHandler;
    private readonly transport;
    constructor(transport: MqttTransportSettings);
    getTransportSettings(): MqttTransportSettings;
    getConnectionState(): MqttConnectionState;
    connect(brokerUrl: string, options?: Record<string, unknown>): Promise<void>;
    disconnect(): Promise<void>;
    subscribe(topic: string): Promise<void>;
    publish(topic: string, payload: string, options?: OutboundPublishOptions): Promise<void>;
    onMessage(handler: (topic: string, payload: Buffer) => void): void;
    private connectInternal;
    private requireClient;
}
