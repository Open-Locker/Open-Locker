import type { IClientOptions } from 'mqtt';
/**
 * Last Will for unexpected disconnect (AsyncAPI: locker/{uuid}/state/connection).
 */
export declare function connectionLostWillOptions(lockerUuid: string, nowIso?: () => string): Pick<IClientOptions, 'will'>;
