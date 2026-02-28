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
  const defaultAppIdBase = 'com.openlocker.mobileapp';
  const existingEasProjectId = (config.extra as { eas?: { projectId?: string } } | undefined)?.eas
    ?.projectId;
  const easProjectId = process.env.EXPO_EAS_PROJECT_ID ?? existingEasProjectId;
  const appIdBase = process.env.APP_ID_BASE ?? defaultAppIdBase;
  const iosAppIdBase = process.env.APP_ID_BASE_IOS ?? appIdBase;
  const androidAppIdBase = process.env.APP_ID_BASE_ANDROID ?? appIdBase;
  const appleTeamId = process.env.EXPO_APPLE_TEAM_ID;
  const appDomain = 'open-locker.cloud';
  const marketingDomain = 'open-locker.org';

  if (variant === 'production') {
    const iosUsesDefaultId = iosAppIdBase === defaultAppIdBase;
    const androidUsesDefaultId = androidAppIdBase === defaultAppIdBase;
    if (iosUsesDefaultId || androidUsesDefaultId) {
      throw new Error(
        'Production builds require explicit APP_ID_BASE, APP_ID_BASE_IOS, or APP_ID_BASE_ANDROID.',
      );
    }
  }

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
      bundleIdentifier: `${iosAppIdBase}${suffix}`,
      associatedDomains: [`applinks:${appDomain}`, `applinks:${marketingDomain}`],
      infoPlist: {
        ITSAppUsesNonExemptEncryption: false,
      },
    },
    android: {
      ...config.android,
      package: `${androidAppIdBase}${suffix}`,
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
    plugins: [
      'expo-router',
      [
        'expo-localization',
        {
          supportedLocales: {
            ios: ['en', 'de'],
            android: ['en', 'de'],
          },
        },
      ],
    ],
    experiments: {
      typedRoutes: true,
    },
    extra: {
      ...config.extra,
      ...(easProjectId
        ? {
            eas: {
              ...((config.extra as { eas?: Record<string, unknown> } | undefined)?.eas ?? {}),
              projectId: easProjectId,
            },
          }
        : {}),
      appVariant: variant,
      appDomain,
      marketingDomain,
    },
  };
};
