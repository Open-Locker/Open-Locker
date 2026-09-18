import {
  isOrganizationForbiddenError,
  isOrganizationSelectionRequiredError,
} from '@/src/store/organizationGate';

describe('organization gate detection', () => {
  it('recognises the selection-required refusal by its code', () => {
    expect(isOrganizationSelectionRequiredError({ code: 'organization_not_selected' })).toBe(true);
  });

  it('recognises the forbidden refusal by its code', () => {
    expect(isOrganizationForbiddenError({ code: 'organization_forbidden' })).toBe(true);
  });

  it('does not confuse the two refusals', () => {
    expect(isOrganizationForbiddenError({ code: 'organization_not_selected' })).toBe(false);
    expect(isOrganizationSelectionRequiredError({ code: 'organization_forbidden' })).toBe(false);
  });

  it('never matches on the message, which is localised and free to be reworded', () => {
    expect(isOrganizationSelectionRequiredError({ message: 'organization_not_selected' })).toBe(
      false,
    );
    expect(isOrganizationSelectionRequiredError('organization_not_selected')).toBe(false);
    expect(isOrganizationSelectionRequiredError(null)).toBe(false);
  });
});
