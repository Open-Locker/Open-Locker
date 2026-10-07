import { useEffect } from 'react';

import { useGetOrganizationsQuery } from '@/src/store/generatedApi';
import { useAppDispatch } from '@/src/store/hooks';

import { organizationCompartmentsApi } from './organizationCompartmentsApi';

/**
 * Keeps every organization's lockers loaded and subscribed, so switching shows
 * them at once instead of fetching. Held as subscriptions rather than one-off
 * prefetches, which RTK Query would evict after a minute unused. Does nothing
 * for someone with one organization.
 */
export function useAllOrganizationCompartments(enabled: boolean): void {
  const dispatch = useAppDispatch();
  const { data: organizations } = useGetOrganizationsQuery({}, { skip: !enabled });
  const organizationIds = (organizations ?? []).map((organization) => organization.id).join(',');

  useEffect(() => {
    if (!enabled || !organizationIds.includes(',')) return;

    const subscriptions = organizationIds.split(',').map((organizationId) =>
      dispatch(
        organizationCompartmentsApi.endpoints.getOrganizationCompartments.initiate({
          organizationId,
        }),
      ),
    );

    return () => {
      for (const subscription of subscriptions) subscription.unsubscribe();
    };
  }, [dispatch, enabled, organizationIds]);
}
