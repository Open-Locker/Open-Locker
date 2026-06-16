import type { CompartmentConfig } from "../domain/compartment";
import type { LockerConfig, RuntimeConfigOverlay } from "../domain/config";
export interface ConfigRepositoryPort {
    load(): LockerConfig;
    reload(): LockerConfig;
    getCompartmentConfig(compartmentNumber: number): CompartmentConfig | null;
    hasExplicitRuntimeCompartments(): boolean;
    getFlashDurationMs(): number;
    getHeartbeatIntervalSeconds(): number;
    getMqttTransportSettings(): import("./mqtt.port").MqttTransportSettings;
}
export interface RuntimeOverlayStorePort {
    load(): RuntimeConfigOverlay | null;
    save(overlay: RuntimeConfigOverlay): RuntimeConfigOverlay;
    clear(): void;
}
export interface CredentialStorePort {
    getCredentials(): {
        username: string;
        password: string;
    } | null;
    saveCredentials(credentials: {
        username: string;
        password: string;
    }): void;
    isProvisioned(): boolean;
    markProvisioned(): void;
}
export interface ClockPort {
    nowIso(): string;
}
export interface SchedulerPort {
    scheduleAfter(delayMs: number, fn: () => Promise<void>): () => void;
    cancelAll(): void;
}
