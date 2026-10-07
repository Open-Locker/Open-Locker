import { createSlice, type PayloadAction } from '@reduxjs/toolkit';

import {
  clearPersistedOrganization,
  persistActiveOrganization,
} from '@/src/store/organizationStorage';

type OrganizationState = {
  /**
   * Which organization the app is acting in. Sent with every request; the
   * server validates it against membership and never trusts it on its own.
   *
   * Null is the ordinary case: a user who belongs to one organization never
   * picks it, and the API resolves that single membership itself. Only a user
   * with several ever sets this, by choosing in the switcher.
   */
  activeOrganizationId: string | null;
};

const initialState: OrganizationState = {
  activeOrganizationId: null,
};

const organizationSlice = createSlice({
  name: 'organization',
  initialState,
  reducers: {
    restoreActiveOrganization(state, action: PayloadAction<string | null>) {
      state.activeOrganizationId = action.payload;
    },
    setActiveOrganization(state, action: PayloadAction<string>) {
      state.activeOrganizationId = action.payload;
    },
    clearActiveOrganization(state) {
      state.activeOrganizationId = null;
    },
  },
});

export const { restoreActiveOrganization, setActiveOrganization, clearActiveOrganization } =
  organizationSlice.actions;

export const organizationReducer = organizationSlice.reducer;

/**
 * Persistence lives beside the reducer, not inside it.
 *
 * A reducer must be a pure function of its inputs: writing to storage from one
 * happens to work today, but it runs again under StrictMode's double-invocation
 * and during replay, which is exactly the kind of bug that only shows up in
 * development builds.
 */
export const organizationPersistenceListener = {
  matcher: (action: { type: string }) =>
    action.type === setActiveOrganization.type || action.type === clearActiveOrganization.type,
  effect: (action: { type: string; payload?: unknown }) => {
    if (action.type === setActiveOrganization.type && typeof action.payload === 'string') {
      return persistActiveOrganization(action.payload);
    }

    return clearPersistedOrganization();
  },
};
