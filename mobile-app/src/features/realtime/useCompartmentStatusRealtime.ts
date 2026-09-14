import { skipToken } from '@reduxjs/toolkit/query';
import { useEffect } from 'react';
import { AppState } from 'react-native';

import { openLockerApi, useGetUserQuery } from '@/src/store/generatedApi';
import { useAppDispatch, useAppSelector } from '@/src/store/hooks';

import { applyContentNote } from './applyContentNote';
import { applyDoorState } from './applyDoorState';
import {
  createEcho,
  type CompartmentDoorStateUpdatedPayload,
  type CompartmentNoteUpdatedPayload,
} from './echo';

const DOOR_STATE_EVENT = '.compartment.door_state.updated';
const CONTENT_NOTE_EVENT = '.compartment.content_note.updated';
/** Must match `TermsAcceptanceRequired::broadcastAs()`. The leading dot stops Echo
 *  prefixing its namespace. */
export const TERMS_ACCEPTANCE_EVENT = '.terms.acceptance-required';

/** Must match the channel authorised in the backend's routes/channels.php. */
export function accountChannelName(userId: number | string): string {
  return `users.${userId}.account`;
}

/**
 * Subscribes the signed-in user to their private compartment-status channel and
 * keeps the `getCompartmentsAccessible` cache live:
 *
 * - On `.compartment.door_state.updated`, patches the matching compartment's
 *   `door_state` in place (no refetch).
 * - On `.compartment.content_note.updated`, patches the matching compartment's
 *   `content_note` fields in place (no refetch).
 * - Falls back to a REST refetch when realtime is untrustworthy: the socket
 *   reports unavailable/disconnected, or the app returns to the foreground
 *   (events sent while backgrounded are not replayed).
 *
 * `door_state` is sourced only from the API and this event — open-command
 * feedback stays on the mutation path and is never derived here.
 */
export function useCompartmentStatusRealtime(): void {
  const token = useAppSelector((state) => state.auth.token);
  const { data: user } = useGetUserQuery(token ? undefined : skipToken);
  const userId = user?.id;
  const dispatch = useAppDispatch();

  useEffect(() => {
    if (!token || userId == null) {
      return;
    }

    const echo = createEcho(token);
    const channelName = `users.${userId}.compartment-status`;
    // Account-level state rides the same socket. A second Echo instance would mean
    // a second websocket per session for one rare event.
    const accountChannel = accountChannelName(userId);

    const handleDoorState = (payload: CompartmentDoorStateUpdatedPayload) => {
      dispatch(
        openLockerApi.util.updateQueryData('getCompartmentsAccessible', undefined, (draft) => {
          applyDoorState(draft, payload);
        }),
      );
    };

    const handleContentNote = (payload: CompartmentNoteUpdatedPayload) => {
      dispatch(
        openLockerApi.util.updateQueryData('getCompartmentsAccessible', undefined, (draft) => {
          applyContentNote(draft, payload);
        }),
      );
    };

    // Independent of the socket: a plain REST refetch to reconcile missed events.
    // Runs when the socket drops and when the app returns to the foreground.
    // `Auth` is included because the user's terms acceptance goes stale the same
    // way compartment state does, and restarting the app was the only thing that
    // refreshed it.
    const refetchFallback = () => {
      dispatch(openLockerApi.util.invalidateTags(['Compartment', 'Auth']));
    };

    // The payload carries only a version; the profile is re-read rather than
    // patched, so there is one answer to "must I accept" and it comes from the API.
    const handleTermsAcceptanceRequired = () => {
      dispatch(openLockerApi.util.invalidateTags(['Auth']));
    };

    echo
      .private(channelName)
      .listen(DOOR_STATE_EVENT, handleDoorState)
      .listen(CONTENT_NOTE_EVENT, handleContentNote);

    echo.private(accountChannel).listen(TERMS_ACCEPTANCE_EVENT, handleTermsAcceptanceRequired);

    const connection = (echo.connector as { pusher: { connection: PusherConnection } }).pusher
      .connection;
    connection.bind('unavailable', refetchFallback);
    connection.bind('disconnected', refetchFallback);

    const appStateSub = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        refetchFallback();
      }
    });

    return () => {
      appStateSub.remove();
      connection.unbind('unavailable', refetchFallback);
      connection.unbind('disconnected', refetchFallback);
      echo.leave(channelName);
      echo.leave(accountChannel);
      echo.disconnect();
    };
  }, [token, userId, dispatch]);
}

type PusherConnection = {
  bind: (event: string, handler: () => void) => void;
  unbind: (event: string, handler: () => void) => void;
};
