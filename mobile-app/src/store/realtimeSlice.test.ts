import { isLivePaused } from './realtimeSlice';

describe('isLivePaused', () => {
  it('pauses when the socket drops', () => {
    expect(['unavailable', 'failed', 'disconnected'].map((s) => isLivePaused(false, s))).toEqual([
      true,
      true,
      true,
    ]);
  });

  it('resumes once connected', () => {
    expect(isLivePaused(true, 'connected')).toBe(false);
  });

  it('keeps the previous answer while connecting', () => {
    expect([isLivePaused(false, 'connecting'), isLivePaused(true, 'connecting')]).toEqual([
      false,
      true,
    ]);
  });
});
