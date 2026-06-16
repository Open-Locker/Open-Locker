import type { CredentialStorePort } from '../ports/config.port';
import type { MessageTransportPort } from '../ports/mqtt.port';
export declare const DEFAULT_MQTT_BROKER_URL = "mqtt://open-locker.cloud";
export declare function getOrCreateClientId(clientIdFilePath: string): string;
export declare function getRequiredProvisioningDefaults(): {
    defaultUsername: string;
    defaultPassword: string;
};
export declare function provisionDevice(options: {
    transport: MessageTransportPort;
    brokerUrl: string;
    clientId: string;
    provisioningToken: string;
    credentialStore: CredentialStorePort;
}): Promise<void>;
