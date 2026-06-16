import type { LockerConfig } from '../../domain/config';
import type { ConfigRepositoryPort } from '../../ports/config.port';
import type { MqttTransportSettings } from '../../ports/mqtt.port';
import { FileRuntimeOverlayStore } from './runtime-overlay.store';
export declare class YamlConfigRepository implements ConfigRepositoryPort {
    private readonly overlayStore;
    private config;
    private explicitRuntimeCompartments;
    constructor(overlayStore?: FileRuntimeOverlayStore);
    load(): LockerConfig;
    reload(): LockerConfig;
    getCompartmentConfig(compartmentNumber: number): import("../../domain/compartment").CompartmentConfig | null;
    hasExplicitRuntimeCompartments(): boolean;
    getFlashDurationMs(): number;
    getHeartbeatIntervalSeconds(): number;
    getMqttTransportSettings(): MqttTransportSettings;
    getConfiguredSlaveIds(): number[];
}
