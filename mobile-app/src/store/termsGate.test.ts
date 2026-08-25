import { isTermsNotAcceptedError } from './termsGate';

describe('isTermsNotAcceptedError', () => {
  it('recognises the gate by its code', () => {
    expect(
      isTermsNotAcceptedError({
        message: 'You must accept the latest terms before continuing.',
        code: 'terms_not_accepted',
        terms_current_version: 3,
      }),
    ).toBe(true);
  });

  it('ignores other refusals that happen to be 403', () => {
    // Access denied to a compartment is also a 403, and must not quietly refetch
    // the profile as though the terms had changed.
    expect(isTermsNotAcceptedError({ code: 'forbidden' })).toBe(false);
    expect(isTermsNotAcceptedError({ message: 'Forbidden' })).toBe(false);
  });

  it('is not fooled by a message mentioning terms', () => {
    expect(isTermsNotAcceptedError({ message: 'terms_not_accepted' })).toBe(false);
  });

  it('handles bodies that are not objects at all', () => {
    expect(isTermsNotAcceptedError('terms_not_accepted')).toBe(false);
    expect(isTermsNotAcceptedError(null)).toBe(false);
    expect(isTermsNotAcceptedError(undefined)).toBe(false);
  });
});
