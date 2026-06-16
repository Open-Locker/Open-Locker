import type { ApplyConfigCommand } from '../domain/mqtt-schemas';
import type { ConfigRepositoryPort, RuntimeOverlayStorePort } from '../ports/config.port';
import type { LockerBusPort } from '../ports/locker-bus.port';
export interface ApplyConfigResult {
    appliedConfigHash: string;
    message?: string;
}
export interface ApplyConfigDependencies {
    overlayStore: RuntimeOverlayStorePort;
    config: ConfigRepositoryPort;
    bus: LockerBusPort;
    restartHeartbeat: () => void;
    restartPolling: () => void;
}
export declare class ApplyConfigUseCase {
    private readonly deps;
    constructor(deps: ApplyConfigDependencies);
    execute(command: ApplyConfigCommand): Promise<ApplyConfigResult>;
    private buildOverlay;
    private validateCompartments;
    private rollback;
}
