export interface AppContext {
    shutdown(): Promise<void>;
}
export declare function createApp(): Promise<AppContext>;
