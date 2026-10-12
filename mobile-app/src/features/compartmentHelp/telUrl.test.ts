import { toTelUrl } from './telUrl';

describe('toTelUrl', () => {
  it('keeps only digits and a leading plus', () => {
    expect(toTelUrl('+49 (30) 123-45 67')).toBe('tel:+49301234567');
  });
});
