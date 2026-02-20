import { normalizeApiBaseUrl } from '@/src/api/baseUrl';

describe('normalizeApiBaseUrl', () => {
  it('adds https and api suffix for host-only input', () => {
    expect(normalizeApiBaseUrl('selfhost.example.com')).toBe('https://selfhost.example.com/api');
  });

  it('keeps existing /api path', () => {
    expect(normalizeApiBaseUrl('https://selfhost.example.com/api')).toBe(
      'https://selfhost.example.com/api',
    );
  });

  it('returns null for invalid input', () => {
    expect(normalizeApiBaseUrl('')).toBeNull();
    expect(normalizeApiBaseUrl('not a url value')).toBeNull();
  });
});
