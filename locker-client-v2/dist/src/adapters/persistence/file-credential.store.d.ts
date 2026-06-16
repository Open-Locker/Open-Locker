import type { CredentialStorePort } from '../../ports/config.port';
export declare class FileCredentialStore implements CredentialStorePort {
    getCredentials(): {
        username: string;
        password: string;
    } | null;
    saveCredentials(credentials: {
        username: string;
        password: string;
    }): void;
    isProvisioned(): boolean;
    markProvisioned(): void;
}
