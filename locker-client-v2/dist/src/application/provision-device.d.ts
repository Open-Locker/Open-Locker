import type { MessageTransportPort } from "../ports/mqtt.port";
import { FileCredentialStore } from "../adapters/persistence/file-credential.store";
export declare const DEFAULT_MQTT_BROKER_URL = "mqtt://open-locker.cloud";
export declare function getOrCreateClientId(): string;
export declare function getRequiredProvisioningDefaults(): {
    defaultUsername: string;
    defaultPassword: string;
};
export declare function provisionDevice(options: {
    transport: MessageTransportPort;
    brokerUrl: string;
    clientId: string;
    provisioningToken: string;
    credentialStore: FileCredentialStore;
}): Promise<void>;
