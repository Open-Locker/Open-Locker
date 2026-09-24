import { skipToken } from '@reduxjs/toolkit/query';
import { useEffect, useState } from 'react';

import {
  openLockerApi,
  useGetCompartmentsOpenRequestsByCommandIdQuery,
} from '@/src/store/generatedApi';

import {
  currentOpenProgress,
  isOpenFinished,
  NO_RESPONSE_AFTER_MS,
  OPEN_STATUS_POLL_MS,
  type OpenProgress,
} from './openProgress';

/**
 * Follows one open request until it finishes. Realtime events patch the query
 * cache (see `applyOpenStatus`); polling covers a dropped socket and stops once
 * the request has an outcome or the locker has not answered in time.
 */
export function useOpenProgress(commandId: string | null): OpenProgress | null {
  const [timedOutCommandId, setTimedOutCommandId] = useState<string | null>(null);

  useEffect(() => {
    if (commandId === null) return;
    const timer = setTimeout(() => setTimedOutCommandId(commandId), NO_RESPONSE_AFTER_MS);
    return () => clearTimeout(timer);
  }, [commandId]);

  const arg = commandId === null ? skipToken : { commandId };
  const { data } =
    openLockerApi.endpoints.getCompartmentsOpenRequestsByCommandId.useQueryState(arg);
  const progress =
    commandId === null ? null : currentOpenProgress(data?.state, timedOutCommandId === commandId);

  useGetCompartmentsOpenRequestsByCommandIdQuery(arg, {
    pollingInterval: progress !== null && !isOpenFinished(progress) ? OPEN_STATUS_POLL_MS : 0,
  });

  return progress;
}
