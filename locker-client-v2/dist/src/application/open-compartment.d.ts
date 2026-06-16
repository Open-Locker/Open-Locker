import type { ConfigRepositoryPort } from '../ports/config.port';
import type { LockerBusPort } from '../ports/locker-bus.port';
import type { SchedulerPort } from '../ports/config.port';
export declare class OpenCompartmentUseCase {
    private readonly bus;
    private readonly config;
    private readonly scheduler;
    private readonly monitoringIntervalMs;
    private readonly monitoringKeys;
    constructor(bus: LockerBusPort, config: ConfigRepositoryPort, scheduler: SchedulerPort, monitoringIntervalMs?: number);
    execute(compartmentNumber: number): Promise<void>;
    stopAllMonitoring(): void;
    private resolveTarget;
    private startRelayMonitoring;
}
export declare function runStartupFailsafe(bus: LockerBusPort): Promise<void>;
