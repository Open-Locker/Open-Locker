import { skipToken } from '@reduxjs/toolkit/query';
import { useEffect, useState } from 'react';

import {
  openLockerApi,
  useGetCompartmentsOpenRequestsByCommandIdQuery,
} from '@/src/store/generatedApi';

import {
  currentOpenProgress,
  isOpenStateAdvance,
  NO_RESPONSE_AFTER_MS,
  openStatusPollInterval,
  type OpenProgress,
} from './openProgress';

type KnownState = { commandId: string; state: string };

/**
 * Follows one open request until it finishes. Realtime events patch the query
 * cache (see `applyOpenStatus`); polling covers a dropped socket and runs, more
 * slowly after the app's timeout, until the backend reports an outcome or the
 * sheet stops following the request.
 */
export function useOpenProgress(commandId: string | null): OpenProgress | null {
  const [timedOutCommandId, setTimedOutCommandId] = useState<string | null>(null);
  const [known, setKnown] = useState<KnownState | null>(null);

  useEffect(() => {
    if (commandId === null) return;
    const timer = setTimeout(() => setTimedOutCommandId(commandId), NO_RESPONSE_AFTER_MS);
    return () => clearTimeout(timer);
  }, [commandId]);

  const arg = commandId === null ? skipToken : { commandId };
  const { data } =
    openLockerApi.endpoints.getCompartmentsOpenRequestsByCommandId.useQueryState(arg);
  const reportedState = data?.state;

  // A poll that started before a realtime event can land after it and write the
  // older state back into the cache; keep the furthest state seen instead.
  useEffect(() => {
    if (commandId === null || reportedState === undefined) return;
    setKnown((previous) =>
      previous?.commandId === commandId && !isOpenStateAdvance(previous.state, reportedState)
        ? previous
        : { commandId, state: reportedState },
    );
  }, [commandId, reportedState]);

  const knownState = known?.commandId === commandId ? known.state : undefined;
  const timedOut = timedOutCommandId === commandId;
  const progress = commandId === null ? null : currentOpenProgress(knownState, timedOut);

  useGetCompartmentsOpenRequestsByCommandIdQuery(arg, {
    pollingInterval: commandId === null ? 0 : openStatusPollInterval(knownState, timedOut),
  });

  return progress;
}
