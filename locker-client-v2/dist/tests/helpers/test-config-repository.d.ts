import type { CompartmentConfig } from '../../src/domain/compartment';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';
export declare function createTestConfigRepository(overrides?: Partial<ConfigRepositoryPort> & {
    compartments?: CompartmentConfig[];
}): ConfigRepositoryPort;
