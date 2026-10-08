import { getApiBaseUrl } from '@/src/api/baseUrl';

export type ReverbConnectionConfig = {
  key: string;
  wsHost: string;
  wsPort: number;
  wssPort: number;
  forceTLS: boolean;
};

export type ReverbConfigEnv = {
  reverbScheme?: string;
  reverbPort?: string;
  reverbHost?: string;
  reverbKey?: string;
};

function hostnameFromApiBaseUrl(apiBaseUrl: string): string {
  try {
    return new URL(apiBaseUrl).hostname;
  } catch {
    return 'localhost';
  }
}

function apiUsesHttps(apiBaseUrl: string): boolean {
  try {
    return new URL(apiBaseUrl).protocol === 'https:';
  } catch {
    return false;
  }
}

/**
 * Resolves Reverb WebSocket settings from the API base URL and optional
 * EXPO_PUBLIC_REVERB_* overrides. Channel auth still uses the API host
 * (`/broadcasting/auth`); only the socket target is configured here.
 */
export function resolveReverbConfig(
  apiBaseUrl: string,
  env: ReverbConfigEnv = {},
): ReverbConnectionConfig {
  const productionLikeApi = apiUsesHttps(apiBaseUrl);
  const scheme = env.reverbScheme ?? (productionLikeApi ? 'https' : 'http');
  const forceTLS = scheme === 'https';
  const defaultPort = productionLikeApi ? '443' : '48080';
  const port = Number(env.reverbPort ?? defaultPort);

  return {
    key: env.reverbKey ?? 'open-locker-key',
    wsHost: env.reverbHost ?? hostnameFromApiBaseUrl(apiBaseUrl),
    wsPort: port,
    wssPort: port,
    forceTLS,
  };
}

export function reverbConfig(): ReverbConnectionConfig {
  return resolveReverbConfig(getApiBaseUrl(), {
    reverbScheme: process.env.EXPO_PUBLIC_REVERB_SCHEME,
    reverbPort: process.env.EXPO_PUBLIC_REVERB_PORT,
    reverbHost: process.env.EXPO_PUBLIC_REVERB_HOST,
    reverbKey: process.env.EXPO_PUBLIC_REVERB_KEY,
  });
}
