import {
  clearActiveOrganization,
  organizationReducer,
  requireOrganizationSelection,
  setActiveOrganization,
} from '@/src/store/organizationSlice';

const initial = organizationReducer(undefined, { type: '@@INIT' });

describe('organization slice', () => {
  it('starts with no active organization', () => {
    // The ordinary case: one membership, resolved by the server, never chosen
    // by the app.
    expect(initial.activeOrganizationId).toBeNull();
    expect(initial.selectionRequired).toBe(false);
  });

  it('choosing an organization clears the pending selection', () => {
    const asked = organizationReducer(initial, requireOrganizationSelection());
    expect(asked.selectionRequired).toBe(true);

    const chosen = organizationReducer(asked, setActiveOrganization('org-1'));
    expect(chosen.activeOrganizationId).toBe('org-1');
    expect(chosen.selectionRequired).toBe(false);
  });

  it('clearing forgets the stored choice without asking again', () => {
    // Used when the server says the stored organization is forbidden: choosing
    // again cannot fix a stored value, so it is dropped first.
    const chosen = organizationReducer(initial, setActiveOrganization('org-1'));
    const cleared = organizationReducer(chosen, clearActiveOrganization());

    expect(cleared.activeOrganizationId).toBeNull();
    expect(cleared.selectionRequired).toBe(false);
  });
});
