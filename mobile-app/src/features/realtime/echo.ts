import Echo from 'laravel-echo';
import * as PusherNS from 'pusher-js';
import type { ChannelAuthorizationData } from 'pusher-js/types/src/core/auth/options';

import { getApiBaseUrl } from '@/src/api/baseUrl';

// pusher-js's React Native build exports the client as a NAMED `Pusher` export
// (`module.exports.Pusher = ...`), while the web/node builds use the default
// export. Resolve whichever variant Metro bundles so the connector receives an
// actual constructor.
const Pusher = ((PusherNS as { Pusher?: unknown }).Pusher ??
  (PusherNS as { default?: unknown }).default ??
  PusherNS) as typeof import('pusher-js').default;

export type CompartmentDoorStateUpdatedPayload = {
  compartment_id: string;
  door_state: string;
  door_state_changed_at: string | null;
};

export type CompartmentNoteUpdatedPayload = {
  compartment_id: string;
  content_note: string | null;
  content_note_updated_at: string | null;
  content_note_updated_by_user_id: string | null;
};

/**
 * Host the API base URL resolves to (e.g. `localhost` on iOS, `10.0.2.2` on the
 * Android emulator, or a remote host). Reverb runs on the same machine as the
 * API in every environment, so defaulting the socket host to this keeps it
 * platform-correct without a separate per-platform Reverb host config.
 */
function apiHost(): string {
  try {
    return new URL(getApiBaseUrl()).hostname;
  } catch {
    return 'localhost';
  }
}

/**
 * Reverb speaks the Pusher protocol, so the app connects with pusher-js
 * pointed at the Reverb host/port. The host defaults to the API host (so the
 * Android emulator's `10.0.2.2` is handled automatically); the port defaults to
 * the local Sail dev stack (Reverb published on :8080). Production overrides via
 * EXPO_PUBLIC_REVERB_*.
 */
function reverbConfig() {
  const scheme = process.env.EXPO_PUBLIC_REVERB_SCHEME ?? 'http';
  const forceTLS = scheme === 'https';
  const port = Number(process.env.EXPO_PUBLIC_REVERB_PORT ?? '8080');

  return {
    key: process.env.EXPO_PUBLIC_REVERB_KEY ?? 'open-locker-key',
    wsHost: process.env.EXPO_PUBLIC_REVERB_HOST ?? apiHost(),
    wsPort: port,
    wssPort: port,
    forceTLS,
  };
}

/**
 * The broadcasting auth endpoint lives at the app root (not under `/api`),
 * so strip the `/api` suffix the base URL carries for REST calls.
 */
function broadcastingAuthUrl(): string {
  return `${getApiBaseUrl().replace(/\/api\/?$/i, '')}/broadcasting/auth`;
}

/**
 * Creates an Echo client authenticated with the same Sanctum bearer token the
 * REST API uses. The custom authorizer POSTs the handshake to the backend's
 * token-guarded `/broadcasting/auth` route so private channels authorize
 * without a web session.
 */
export function createEcho(token: string): Echo<'reverb'> {
  const { key, wsHost, wsPort, wssPort, forceTLS } = reverbConfig();
  const authUrl = broadcastingAuthUrl();

  return new Echo({
    broadcaster: 'reverb',
    // Pass the client explicitly; React Native has no global `window.Pusher`
    // for the connector to fall back to.
    Pusher,
    key,
    wsHost,
    wsPort,
    wssPort,
    forceTLS,
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
      authorize: (
        socketId: string,
        callback: (error: Error | null, data: ChannelAuthorizationData | null) => void,
      ) => {
        fetch(authUrl, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        })
          .then(async (response) => {
            if (!response.ok) {
              throw new Error(`broadcasting/auth failed: ${response.status}`);
            }
            return response.json();
          })
          .then((data: ChannelAuthorizationData) => callback(null, data))
          .catch((error: Error) => callback(error, null));
      },
    }),
  });
}
