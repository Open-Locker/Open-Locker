import type { RuntimeConfigOverlay } from '../../src/domain/config';
import type { RuntimeOverlayStorePort } from '../../src/ports/config.port';
export declare class MemoryOverlayStore implements RuntimeOverlayStorePort {
    private overlay;
    load(): RuntimeConfigOverlay | null;
    save(overlay: RuntimeConfigOverlay): RuntimeConfigOverlay;
    clear(): void;
}
