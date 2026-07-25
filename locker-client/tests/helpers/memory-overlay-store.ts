import type { RuntimeConfigOverlay } from '../../src/domain/config';
import type { RuntimeOverlayStorePort } from '../../src/ports/config.port';

export class MemoryOverlayStore implements RuntimeOverlayStorePort {
  private overlay: RuntimeConfigOverlay | null;

  constructor(initial: RuntimeConfigOverlay | null = null) {
    this.overlay = initial;
  }

  load() {
    return this.overlay;
  }

  save(overlay: RuntimeConfigOverlay) {
    this.overlay = overlay;
    return overlay;
  }

  clear() {
    this.overlay = null;
  }
}
