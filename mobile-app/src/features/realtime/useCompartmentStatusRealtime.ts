import { skipToken } from '@reduxjs/toolkit/query';
import { useEffect } from 'react';
import { AppState } from 'react-native';

import { openLockerApi, useGetUserQuery, useIdentifyQuery } from '@/src/store/generatedApi';
import { useAppDispatch, useAppSelector } from '@/src/store/hooks';
import {
  realtimeConnectionChanged,
  realtimeReset,
  realtimeSubscriptionFailed,
  realtimeSubscriptionSucceeded,
} from '@/src/store/realtimeSlice';

import { applyBankConnection } from './applyBankConnection';
import { applyContentNote } from './applyContentNote';
import { applyDoorState } from './applyDoorState';
import { applyOpenStatus } from './applyOpenStatus';
import {
  createEcho,
  type CompartmentDoorStateUpdatedPayload,
  type CompartmentNoteUpdatedPayload,
  type CompartmentOpenStatusUpdatedPayload,
  type LockerBankConnectionUpdatedPayload,
} from './echo';

const DOOR_STATE_EVENT = '.compartment.door_state.updated';
const CONTENT_NOTE_EVENT = '.compartment.content_note.updated';
/** Must match `CompartmentOpenStatusUpdated::broadcastAs()`. */
const OPEN_STATUS_EVENT = '.compartment.open.status.updated';
/** Must match `LockerBankConnectionUpdated::broadcastAs()`. */
export const BANK_CONNECTION_EVENT = '.locker_bank.connection.updated';
/** Reverb's `pusher:error` code for a channel signature it cannot verify. */
const UNAUTHORIZED_CONNECTION_ERROR = 4009;
/** Must match `TermsAcceptanceRequired::broadcastAs()`. The leading dot stops Echo
 *  prefixing its namespace. */
export const TERMS_ACCEPTANCE_EVENT = '.terms.acceptance-required';

/** Must match the channel authorised in the backend's routes/channels.php. */
export function accountChannelName(userId: number | string): string {
  return `users.${userId}.account`;
}

/** Must match the channel authorised in the backend's routes/channels.php. */
export function lockerBankChannelName(userId: number | string): string {
  return `users.${userId}.locker-banks`;
}

/**
 * Subscribes the signed-in user to their private compartment-status channel and
 * keeps the `getCompartmentsAccessible` cache live:
 *
 * - On `.compartment.door_state.updated`, patches the matching compartment's
 *   `door_state` in place (no refetch).
 * - On `.compartment.content_note.updated`, patches the matching compartment's
 *   `content_note` fields in place (no refetch).
 * - On `.compartment.open.status.updated`, advances the cached status of that
 *   open request, if the app is following it (no refetch).
 * - On `.locker_bank.connection.updated` (its own channel), patches the bank's
 *   `connection_status` in place, so a bank going offline recolours without a
 *   refetch. Coming back is immediate; going offline waits for the backend's
 *   heartbeat-timeout sweep, a floor no amount of broadcasting removes.
 * - Falls back to a REST refetch when realtime is untrustworthy: the socket
 *   reports unavailable/disconnected, or the app returns to the foreground
 *   (events sent while backgrounded are not replayed).
 * - Mirrors the socket's state into the `realtime` slice, so the compartment
 *   screen can say when live updates are paused. That is the app's own
 *   connection, not a locker bank's.
 * - Treats a refused channel subscription the same way: the socket stays
 *   `connected` but that channel receives nothing, so it pauses and refetches.
 *
 * `door_state` is sourced only from the API and the door-state event, and
 * open-command feedback only from the open request's own status (ADR-0023) —
 * neither is derived from the other.
 *
 * The socket targets the Reverb server the selected API server advertises in
 * `GET /identify`, so it waits for that answer. A server that advertises none
 * gets no socket and the paused notice (ADR-0087).
 */
export function useCompartmentStatusRealtime(): void {
  const token = useAppSelector((state) => state.auth.token);
  const { data: user } = useGetUserQuery(token ? {} : skipToken);
  const userId = user?.id;
  // Same `{}` args as the sign-in screen, so this is usually already cached.
  const { data: identify, isLoading: isIdentifyLoading } = useIdentifyQuery(token ? {} : skipToken);
  const advertisedReverb = identify?.realtime;
  const dispatch = useAppDispatch();

  useEffect(() => {
    if (!token || userId == null || isIdentifyLoading) {
      return;
    }

    // Independent of the socket: a plain REST refetch to reconcile missed events.
    // Runs when the app returns to the foreground (events sent while backgrounded
    // are not replayed), with or without a socket, and when the socket drops or a
    // channel is refused. `Auth` is included because the user's terms acceptance
    // goes stale the same way compartment state does, and restarting the app was
    // the only thing that refreshed it.
    const refetchFallback = () => {
      dispatch(openLockerApi.util.invalidateTags(['Compartment', 'Auth']));
    };
    const appStateSub = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        refetchFallback();
      }
    });

    if (!advertisedReverb) {
      dispatch(realtimeConnectionChanged('unavailable'));
      return () => {
        appStateSub.remove();
        dispatch(realtimeReset());
      };
    }

    const echo = createEcho(token, advertisedReverb);
    const channelName = `users.${userId}.compartment-status`;
    // Account-level state rides the same socket. A second Echo instance would mean
    // a second websocket per session for one rare event.
    const accountChannel = accountChannelName(userId);
    // A bank is not a compartment, so its connectivity has its own channel
    // (ADR-0056's rule). Same Echo instance, so no second websocket.
    const lockerBankChannel = lockerBankChannelName(userId);

    const handleDoorState = (payload: CompartmentDoorStateUpdatedPayload) => {
      dispatch(
        openLockerApi.util.updateQueryData('getCompartmentsAccessible', {}, (draft) => {
          applyDoorState(draft, payload);
        }),
      );
    };

    const handleBankConnection = (payload: LockerBankConnectionUpdatedPayload) => {
      dispatch(
        openLockerApi.util.updateQueryData('getCompartmentsAccessible', {}, (draft) => {
          applyBankConnection(draft, payload);
        }),
      );
    };

    const handleContentNote = (payload: CompartmentNoteUpdatedPayload) => {
      dispatch(
        openLockerApi.util.updateQueryData('getCompartmentsAccessible', {}, (draft) => {
          applyContentNote(draft, payload);
        }),
      );
    };

    const handleOpenStatus = (payload: CompartmentOpenStatusUpdatedPayload) => {
      dispatch(
        openLockerApi.util.updateQueryData(
          'getCompartmentsOpenRequestsByCommandId',
          { commandId: payload.command_id },
          (draft) => {
            applyOpenStatus(draft, payload);
          },
        ),
      );
    };

    // The payload carries only a version; the profile is re-read rather than
    // patched, so there is one answer to "must I accept" and it comes from the API.
    const handleTermsAcceptanceRequired = () => {
      dispatch(openLockerApi.util.invalidateTags(['Auth']));
    };

    const subscribedChannels: string[] = [];
    const handleSubscriptionFailed = (name: string) => {
      dispatch(realtimeSubscriptionFailed(name));
      refetchFallback();
    };
    // Subscribe every channel through here, so a refused one always shows the notice.
    const subscribeTo = (name: string) => {
      subscribedChannels.push(name);
      return echo
        .private(name)
        .subscribed(() => dispatch(realtimeSubscriptionSucceeded(name)))
        .error(() => handleSubscriptionFailed(name));
    };

    subscribeTo(channelName)
      .listen(DOOR_STATE_EVENT, handleDoorState)
      .listen(CONTENT_NOTE_EVENT, handleContentNote)
      .listen(OPEN_STATUS_EVENT, handleOpenStatus);

    subscribeTo(accountChannel).listen(TERMS_ACCEPTANCE_EVENT, handleTermsAcceptanceRequired);
    subscribeTo(lockerBankChannel).listen(BANK_CONNECTION_EVENT, handleBankConnection);

    const connection = (echo.connector as { pusher: { connection: PusherConnection } }).pusher
      .connection;
    const handleStateChange = ({ current }: { current: string }) => {
      dispatch(realtimeConnectionChanged(current));
    };

    // A wrong signature (API and Reverb disagree on the app secret) arrives as a
    // connection error that names no channel; every private channel is refused.
    const handleConnectionError = (error: PusherConnectionError) => {
      if (error?.data?.code === UNAUTHORIZED_CONNECTION_ERROR) {
        subscribedChannels.forEach((name) => dispatch(realtimeSubscriptionFailed(name)));
        refetchFallback();
      }
    };

    connection.bind('error', handleConnectionError);
    connection.bind('unavailable', refetchFallback);
    connection.bind('disconnected', refetchFallback);
    connection.bind('state_change', handleStateChange);
    // Pusher can reach `failed` inside `createEcho`, before anything was bound.
    dispatch(realtimeConnectionChanged(connection.state));

    return () => {
      appStateSub.remove();
      connection.unbind('error', handleConnectionError);
      connection.unbind('unavailable', refetchFallback);
      connection.unbind('disconnected', refetchFallback);
      connection.unbind('state_change', handleStateChange);
      echo.leave(channelName);
      echo.leave(accountChannel);
      echo.leave(lockerBankChannel);
      echo.disconnect();
      dispatch(realtimeReset());
    };
  }, [token, userId, isIdentifyLoading, advertisedReverb, dispatch]);
}

type PusherStateChangeHandler = (change: { current: string }) => void;

type PusherConnectionError = { type?: string; data?: { code?: number; message?: string } };
type PusherConnectionErrorHandler = (error: PusherConnectionError) => void;

type PusherConnection = {
  state: string;
  bind(event: 'state_change', handler: PusherStateChangeHandler): void;
  bind(event: 'error', handler: PusherConnectionErrorHandler): void;
  bind(event: string, handler: () => void): void;
  unbind(event: 'state_change', handler: PusherStateChangeHandler): void;
  unbind(event: 'error', handler: PusherConnectionErrorHandler): void;
  unbind(event: string, handler: () => void): void;
};
