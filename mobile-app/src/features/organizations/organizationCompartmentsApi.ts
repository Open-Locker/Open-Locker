import type { Draft } from '@reduxjs/toolkit';

import { openLockerApi, type GetCompartmentsAccessibleApiResponse } from '@/src/store/generatedApi';
import type { AppDispatch, RootState } from '@/src/store/store';

/**
 * `/compartments/accessible`, cached once per organization.
 *
 * The generated query has one cache entry, which only works if switching
 * organization throws the cache away. Keyed by organization instead, every
 * organization's lockers can be fetched up front and switching just shows a
 * different entry. `organizationId: null` sends no header, so the server picks,
 * which is what a user with one organization always does.
 */
export const organizationCompartmentsApi = openLockerApi.injectEndpoints({
  // Fast Refresh re-runs this module; the endpoint is defined only here.
  overrideExisting: true,
  endpoints: (build) => ({
    getOrganizationCompartments: build.query<
      GetCompartmentsAccessibleApiResponse,
      { organizationId: string | null }
    >({
      query: ({ organizationId }) => ({
        url: '/compartments/accessible',
        headers: organizationId ? { 'x-organization': organizationId } : undefined,
      }),
      providesTags: ['Compartment'],
    }),
  }),
});

export const { useGetOrganizationCompartmentsQuery } = organizationCompartmentsApi;

/**
 * Apply a realtime patch to every organization's cached lockers. Events arrive
 * on one per-user channel whichever organization they concern; a patch that
 * finds nothing to change in a list leaves it alone.
 */
export function patchAllOrganizationCompartments(
  recipe: (draft: Draft<GetCompartmentsAccessibleApiResponse>) => void,
) {
  return (dispatch: AppDispatch, getState: () => RootState) => {
    const cachedArgs = organizationCompartmentsApi.util.selectCachedArgsForQuery(
      getState(),
      'getOrganizationCompartments',
    );

    for (const args of cachedArgs) {
      dispatch(
        organizationCompartmentsApi.util.updateQueryData(
          'getOrganizationCompartments',
          args,
          recipe,
        ),
      );
    }
  };
}
