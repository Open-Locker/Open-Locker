import type { DoorState } from '../domain/compartment';
import type { ConfigRepositoryPort } from '../ports/config.port';
import type { LockerBusPort } from '../ports/locker-bus.port';
import type { OutboundMqttPort } from '../ports/mqtt.port';
import { type LoggerPort } from '../ports/logging.port';
export interface CompartmentSnapshotEntry {
    compartment_number: number;
    door_state: DoorState;
}
export declare class PollCompartmentStateUseCase {
    private readonly bus;
    private readonly config;
    private readonly outbound;
    private readonly snapshotTopic;
    private readonly log;
    private polling;
    constructor(bus: LockerBusPort, config: ConfigRepositoryPort, outbound: OutboundMqttPort, snapshotTopic: string, log?: LoggerPort);
    pollAndPublish(force?: boolean): Promise<void>;
    private collectSnapshots;
}
export declare class HeartbeatUseCase {
    private readonly outbound;
    private readonly topic;
    private intervalMs;
    private readonly log;
    private timer;
    private readonly startTime;
    constructor(outbound: OutboundMqttPort, topic: string, intervalMs: number, log?: LoggerPort);
    start(): void;
    stop(): void;
    restart(intervalMs?: number): void;
    private publish;
}
