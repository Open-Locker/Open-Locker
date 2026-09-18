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
  /**
   * Set when the server refuses because the user belongs to several and the app
   * did not say which. Drives the switcher, then clears.
   */
  selectionRequired: boolean;
};

const initialState: OrganizationState = {
  activeOrganizationId: null,
  selectionRequired: false,
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
      state.selectionRequired = false;
      void persistActiveOrganization(action.payload);
    },
    requireOrganizationSelection(state) {
      state.selectionRequired = true;
    },
    clearActiveOrganization(state) {
      state.activeOrganizationId = null;
      state.selectionRequired = false;
      void clearPersistedOrganization();
    },
  },
});

export const {
  restoreActiveOrganization,
  setActiveOrganization,
  requireOrganizationSelection,
  clearActiveOrganization,
} = organizationSlice.actions;

export const organizationReducer = organizationSlice.reducer;
