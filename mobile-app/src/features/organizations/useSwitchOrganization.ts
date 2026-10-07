import { useCallback } from 'react';

import { openLockerApi } from '@/src/store/generatedApi';
import { useAppDispatch } from '@/src/store/hooks';
import { setActiveOrganization } from '@/src/store/organizationSlice';

export function useSwitchOrganization(): (organizationId: string) => void {
  const dispatch = useAppDispatch();

  return useCallback(
    (organizationId: string) => {
      dispatch(setActiveOrganization(organizationId));
      // Lockers are cached per organization (organizationCompartmentsApi), so
      // they stay. The profile and terms answer for the active organization
      // (terms acceptance is per organization), so those are re-read.
      dispatch(openLockerApi.util.invalidateTags(['Auth', 'Terms']));
    },
    [dispatch],
  );
}
