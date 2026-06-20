import { skipToken } from '@reduxjs/toolkit/query';
import { useEffect } from 'react';
import { AppState } from 'react-native';

import { openLockerApi, useGetUserQuery } from '@/src/store/generatedApi';
import { useAppDispatch, useAppSelector } from '@/src/store/hooks';

import { applyDoorState } from './applyDoorState';
import { createEcho, type CompartmentDoorStateUpdatedPayload } from './echo';

const EVENT_NAME = '.compartment.door_state.updated';

/**
 * Subscribes the signed-in user to their private compartment-status channel and
 * keeps the `getCompartmentsAccessible` cache live:
 *
 * - On `.compartment.door_state.updated`, patches the matching compartment's
 *   `door_state` in place (no refetch).
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

    const handleDoorState = (payload: CompartmentDoorStateUpdatedPayload) => {
      dispatch(
        openLockerApi.util.updateQueryData('getCompartmentsAccessible', undefined, (draft) => {
          applyDoorState(draft, payload);
        }),
      );
    };

    // Independent of the socket: a plain REST refetch to reconcile missed events.
    const refetchFallback = () => {
      dispatch(openLockerApi.util.invalidateTags(['Compartment']));
    };

    echo.private(channelName).listen(EVENT_NAME, handleDoorState);

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
      echo.disconnect();
    };
  }, [token, userId, dispatch]);
}

type PusherConnection = {
  bind: (event: string, handler: () => void) => void;
  unbind: (event: string, handler: () => void) => void;
};
