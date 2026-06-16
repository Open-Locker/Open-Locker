import type { CompartmentConfig } from "../../domain/compartment";
import type { RuntimeConfigOverlay } from "../../domain/config";
export declare function normalizeCompartments(compartments: CompartmentConfig[]): CompartmentConfig[];
export declare function computeAppliedConfigHash(compartments: CompartmentConfig[]): string;
export declare function sanitizeRuntimeConfigOverlay(value: unknown): RuntimeConfigOverlay;
export declare class FileRuntimeOverlayStore {
    load(): RuntimeConfigOverlay | null;
    save(overlay: RuntimeConfigOverlay): RuntimeConfigOverlay;
    clear(): void;
}
