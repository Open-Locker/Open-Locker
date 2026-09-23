import { isOrganizationForbiddenError } from '@/src/store/organizationGate';

describe('organization gate detection', () => {
  it('recognises the forbidden refusal by its code', () => {
    expect(isOrganizationForbiddenError({ code: 'organization_forbidden' })).toBe(true);
  });

  it('never matches on the message, which is localised and free to be reworded', () => {
    expect(isOrganizationForbiddenError({ message: 'organization_forbidden' })).toBe(false);
    expect(isOrganizationForbiddenError('organization_forbidden')).toBe(false);
    expect(isOrganizationForbiddenError(null)).toBe(false);
  });
});
