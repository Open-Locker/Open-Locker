import {
  clearActiveOrganization,
  organizationReducer,
  restoreActiveOrganization,
  setActiveOrganization,
} from '@/src/store/organizationSlice';

const initial = organizationReducer(undefined, { type: '@@INIT' });

describe('organization slice', () => {
  it('starts with no active organization', () => {
    // Signing in never asks: the server starts the person in one of the
    // organizations they belong to, and switching happens inside the app.
    expect(initial.activeOrganizationId).toBeNull();
  });

  it('remembers the organization chosen in the switcher', () => {
    const chosen = organizationReducer(initial, setActiveOrganization('org-1'));

    expect(chosen.activeOrganizationId).toBe('org-1');
  });

  it('restores a persisted choice on boot', () => {
    const restored = organizationReducer(initial, restoreActiveOrganization('org-2'));

    expect(restored.activeOrganizationId).toBe('org-2');
  });

  it('clearing forgets the stored choice', () => {
    // Used on logout, and when the server says the stored organization is one
    // this person may not act in.
    const chosen = organizationReducer(initial, setActiveOrganization('org-1'));
    const cleared = organizationReducer(chosen, clearActiveOrganization());

    expect(cleared.activeOrganizationId).toBeNull();
  });
});
