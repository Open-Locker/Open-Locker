import type { ConfigContext } from 'expo/config';

import createAppConfig from './app.config';

describe('release version configuration', () => {
  const originalEnvironment = process.env;

  afterEach(() => {
    process.env = originalEnvironment;
  });

  it('keeps prerelease metadata out of the store marketing version', () => {
    process.env = {
      ...originalEnvironment,
      APP_ID_BASE: 'de.merona.openlocker',
      APP_RELEASE_TAG: 'mobile-v1.0.0-beta.1',
      APP_RELEASE_VERSION: '1.0.0-beta.1',
      APP_RUNTIME_VERSION: '1.0.0-beta.1',
      APP_VARIANT: 'production',
      APP_VERSION: '1.0.0',
    };

    const config = createAppConfig({
      config: {
        name: 'Open Locker',
        slug: 'open-locker-mobile',
      },
    } as ConfigContext);

    expect(config.version).toBe('1.0.0');
    expect(config.runtimeVersion).toBe('1.0.0-beta.1');
    expect(config.extra).toMatchObject({
      releaseTag: 'mobile-v1.0.0-beta.1',
      releaseVersion: '1.0.0-beta.1',
    });
  });
});
