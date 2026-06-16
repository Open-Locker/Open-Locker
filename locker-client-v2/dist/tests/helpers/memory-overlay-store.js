"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.MemoryOverlayStore = void 0;
class MemoryOverlayStore {
    overlay = null;
    load() {
        return this.overlay;
    }
    save(overlay) {
        this.overlay = overlay;
        return overlay;
    }
    clear() {
        this.overlay = null;
    }
}
exports.MemoryOverlayStore = MemoryOverlayStore;
