import Echo from 'laravel-echo';
import * as PusherNS from 'pusher-js';
import type { ChannelAuthorizationData } from 'pusher-js/types/src/core/auth/options';

import { getApiBaseUrl } from '@/src/api/baseUrl';
import { reverbConfig } from '@/src/features/realtime/reverbConfig';

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
  // The backend broadcasts the actor's id as an int (CompartmentNoteUpdated),
  // and the REST payload types it the same way.
  content_note_updated_by_user_id: number;
};

/** Payload of `.compartment.open.status.updated` (CompartmentOpenStatusUpdated, ADR-0045). */
export type CompartmentOpenStatusUpdatedPayload = {
  command_id: string;
  compartment_id: string;
  status: string;
  error_code: string | null;
  message: string | null;
  compartment_number: number | null;
  locker_name: string | null;
};

/** The three states the backend's `connection_status` column can hold. */
export type LockerBankConnectionStatus = 'online' | 'offline' | 'unknown';

/** Payload of `.locker_bank.connection.updated` (LockerBankConnectionUpdated). */
export type LockerBankConnectionUpdatedPayload = {
  locker_bank_id: string;
  connection_status: LockerBankConnectionStatus;
  connection_status_changed_at: string | null;
  last_heartbeat_at: string | null;
};

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
