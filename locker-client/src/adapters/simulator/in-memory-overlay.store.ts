import type { RuntimeConfigOverlay } from '../../domain/config';
import type { RuntimeOverlayStorePort } from '../../ports/config.port';
import { sanitizeRuntimeConfigOverlay } from '../config/runtime-overlay.store';

/**
 * Per-device runtime overlay for the simulator, held in memory.
 *
 * Mirrors `FileRuntimeOverlayStore` exactly — including running every write
 * through the same `sanitizeRuntimeConfigOverlay` — so `apply_config` is
 * validated and rolled back identically to production. Only the storage medium
 * differs, which is what lets several devices share one process without
 * fighting over `RUNTIME_CONFIG_OVERLAY_FILE`.
 */
export class InMemoryRuntimeOverlayStore implements RuntimeOverlayStorePort {
  private overlay: RuntimeConfigOverlay | null;

  constructor(initial: RuntimeConfigOverlay | null = null) {
    this.overlay = initial === null ? null : sanitizeRuntimeConfigOverlay(initial);
  }

  load(): RuntimeConfigOverlay | null {
    return this.overlay === null ? null : structuredClone(this.overlay);
  }

  save(overlay: RuntimeConfigOverlay): RuntimeConfigOverlay {
    this.overlay = sanitizeRuntimeConfigOverlay(overlay);

    return structuredClone(this.overlay);
  }

  clear(): void {
    this.overlay = null;
  }
}
