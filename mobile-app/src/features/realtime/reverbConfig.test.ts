import { resolveReverbConfig } from '@/src/features/realtime/reverbConfig';

describe('resolveReverbConfig', () => {
  it('defaults to http and port 48080 when the API base URL is http', () => {
    expect(resolveReverbConfig('http://10.0.2.2/api')).toEqual({
      key: 'open-locker-key',
      wsHost: '10.0.2.2',
      wsPort: 48080,
      wssPort: 48080,
      forceTLS: false,
    });
  });

  it('defaults to https and port 443 when the API base URL is https', () => {
    expect(resolveReverbConfig('https://open-locker.cloud/api')).toEqual({
      key: 'open-locker-key',
      wsHost: 'open-locker.cloud',
      wsPort: 443,
      wssPort: 443,
      forceTLS: true,
    });
  });

  it('applies explicit EXPO_PUBLIC_REVERB_* overrides over API-derived defaults', () => {
    expect(
      resolveReverbConfig('https://open-locker.cloud/api', {
        reverbScheme: 'http',
        reverbPort: '48080',
        reverbKey: 'custom-key',
      }),
    ).toEqual({
      key: 'custom-key',
      wsHost: 'open-locker.cloud',
      wsPort: 48080,
      wssPort: 48080,
      forceTLS: false,
    });
  });

  it('uses EXPO_PUBLIC_REVERB_HOST when Reverb is on a separate hostname', () => {
    expect(
      resolveReverbConfig('https://open-locker.cloud/api', {
        reverbHost: 'ws.open-locker.cloud',
      }),
    ).toEqual({
      key: 'open-locker-key',
      wsHost: 'ws.open-locker.cloud',
      wsPort: 443,
      wssPort: 443,
      forceTLS: true,
    });
  });
});
