import type { ConfigContext, ExpoConfig } from 'expo/config';

type AppVariant = 'development' | 'preview' | 'production';

function getAppVariant(): AppVariant {
  const raw = process.env.APP_VARIANT ?? 'development';
  if (raw === 'development' || raw === 'preview' || raw === 'production') {
    return raw;
  }

  throw new Error(
    `Invalid APP_VARIANT "${raw}". Expected one of: development, preview, production.`,
  );
}

function getVariantSuffix(variant: AppVariant): string {
  if (variant === 'production') {
    return '';
  }
  if (variant === 'preview') {
    return '.preview';
  }
  return '.dev';
}

function getAppName(variant: AppVariant): string {
  if (variant === 'production') {
    return 'Open Locker';
  }
  if (variant === 'preview') {
    return 'Open Locker (Preview)';
  }
  return 'Open Locker (Dev)';
}

export default ({ config }: ConfigContext): ExpoConfig => {
  const variant = getAppVariant();
  const suffix = getVariantSuffix(variant);
  const appIdBase = process.env.APP_ID_BASE ?? 'com.openlocker.mobileapp';
  const appleTeamId = process.env.EXPO_APPLE_TEAM_ID;
  const appDomain = 'open-locker.cloud';
  const marketingDomain = 'open-locker.org';

  return {
    ...config,
    name: getAppName(variant),
    slug: 'open-locker-mobile',
    version: process.env.APP_VERSION ?? '1.0.0',
    platforms: ['ios', 'android'],
    orientation: 'portrait',
    icon: './assets/images/icon.png',
    scheme: 'open-locker',
    userInterfaceStyle: 'automatic',
    newArchEnabled: true,
    splash: {
      image: './assets/images/splash-icon.png',
      resizeMode: 'contain',
      backgroundColor: '#ffffff',
    },
    runtimeVersion: {
      policy: 'appVersion',
    },
    ios: {
      ...config.ios,
      supportsTablet: true,
      ...(appleTeamId ? { appleTeamId } : {}),
      bundleIdentifier: `${appIdBase}${suffix}`,
      associatedDomains: [`applinks:${appDomain}`, `applinks:${marketingDomain}`],
    },
    android: {
      ...config.android,
      package: `${appIdBase}${suffix}`,
      adaptiveIcon: {
        foregroundImage: './assets/images/adaptive-icon.png',
        backgroundColor: '#ffffff',
      },
      edgeToEdgeEnabled: true,
      predictiveBackGestureEnabled: false,
      intentFilters: [
        {
          action: 'VIEW',
          autoVerify: true,
          data: [
            { scheme: 'https', host: appDomain },
            { scheme: 'https', host: marketingDomain },
          ],
          category: ['BROWSABLE', 'DEFAULT'],
        },
      ],
    },
    plugins: ['expo-router'],
    experiments: {
      typedRoutes: true,
    },
    extra: {
      ...config.extra,
      appVariant: variant,
      appDomain,
      marketingDomain,
    },
  };
};
