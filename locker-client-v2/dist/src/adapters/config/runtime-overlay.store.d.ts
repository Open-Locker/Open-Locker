import type { RuntimeConfigOverlay } from '../../domain/config';
export declare function sanitizeRuntimeConfigOverlay(value: unknown): RuntimeConfigOverlay;
export declare class FileRuntimeOverlayStore {
    load(): RuntimeConfigOverlay | null;
    save(overlay: RuntimeConfigOverlay): RuntimeConfigOverlay;
    clear(): void;
}
