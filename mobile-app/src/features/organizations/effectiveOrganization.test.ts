import { effectiveOrganizationId } from './effectiveOrganization';

const organizations = [{ id: 'org-a' }, { id: 'org-b' }];

describe('effectiveOrganizationId', () => {
  it('keeps the chosen organization', () => {
    expect(effectiveOrganizationId('org-b', organizations)).toBe('org-b');
  });

  it('falls back to the first, as the API does without a header', () => {
    expect(effectiveOrganizationId(null, organizations)).toBe('org-a');
  });

  it('ignores a choice that is no longer a membership', () => {
    expect(effectiveOrganizationId('org-gone', organizations)).toBe('org-a');
  });

  it('is null without any organization', () => {
    expect(effectiveOrganizationId(null, [])).toBeNull();
  });
});
