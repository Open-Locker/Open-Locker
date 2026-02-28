import { DarkTheme, DefaultTheme, type Theme as NavigationTheme } from '@react-navigation/native';
import { MD3DarkTheme, MD3LightTheme } from 'react-native-paper';

import {
  OPEN_LOCKER_DARK_BACKGROUND,
  OPEN_LOCKER_DARK_OUTLINE,
  OPEN_LOCKER_DARK_SURFACE,
  OPEN_LOCKER_DARK_SURFACE_VARIANT,
  OPEN_LOCKER_DESIGN_TOKENS,
  OPEN_LOCKER_LIGHT_BACKGROUND,
  OPEN_LOCKER_LIGHT_OUTLINE,
  OPEN_LOCKER_LIGHT_SURFACE,
  OPEN_LOCKER_LIGHT_SURFACE_VARIANT,
  OPEN_LOCKER_PRIMARY,
} from '@/src/theme/tokens';

export function createPaperTheme(colorScheme: 'dark' | 'light' | null | undefined) {
  if (colorScheme === 'dark') {
    return {
      ...MD3DarkTheme,
      roundness: OPEN_LOCKER_DESIGN_TOKENS.radius.md,
      colors: {
        ...MD3DarkTheme.colors,
        primary: OPEN_LOCKER_PRIMARY,
        onPrimary: '#ffffff',
        primaryContainer: '#243a86',
        onPrimaryContainer: '#dbe4ff',
        secondary: '#9fb6ff',
        onSecondary: '#0f1a40',
        secondaryContainer: '#1b264f',
        onSecondaryContainer: '#d8e1ff',
        tertiary: '#9cb5ff',
        onTertiary: '#121f47',
        tertiaryContainer: '#202d5c',
        onTertiaryContainer: '#d7e1ff',
        background: OPEN_LOCKER_DARK_BACKGROUND,
        onBackground: '#edf1ff',
        surface: OPEN_LOCKER_DARK_SURFACE,
        onSurface: '#edf1ff',
        surfaceVariant: OPEN_LOCKER_DARK_SURFACE_VARIANT,
        onSurfaceVariant: '#b8c3e8',
        outline: OPEN_LOCKER_DARK_OUTLINE,
        outlineVariant: '#384790',
      },
      elevation: {
        level0: OPEN_LOCKER_DARK_SURFACE,
        level1: '#121d3f',
        level2: '#14234c',
        level3: '#172a59',
        level4: '#1a3066',
        level5: '#1d3773',
      },
    };
  }

  return {
    ...MD3LightTheme,
    roundness: OPEN_LOCKER_DESIGN_TOKENS.radius.md,
    colors: {
      ...MD3LightTheme.colors,
      primary: OPEN_LOCKER_PRIMARY,
      onPrimary: '#ffffff',
      primaryContainer: '#dce5ff',
      onPrimaryContainer: '#0f2a75',
      secondary: '#51689b',
      onSecondary: '#ffffff',
      secondaryContainer: '#e2e7f4',
      onSecondaryContainer: '#1f2c4d',
      tertiary: '#405f92',
      onTertiary: '#ffffff',
      tertiaryContainer: '#d8e5ff',
      onTertiaryContainer: '#102848',
      background: OPEN_LOCKER_LIGHT_BACKGROUND,
      onBackground: '#111827',
      surface: OPEN_LOCKER_LIGHT_SURFACE,
      onSurface: '#111827',
      surfaceVariant: OPEN_LOCKER_LIGHT_SURFACE_VARIANT,
      onSurfaceVariant: '#4a587c',
      outline: OPEN_LOCKER_LIGHT_OUTLINE,
      outlineVariant: '#d6defb',
    },
    elevation: {
      level0: OPEN_LOCKER_LIGHT_SURFACE,
      level1: '#ffffff',
      level2: '#ffffff',
      level3: '#ffffff',
      level4: '#ffffff',
      level5: '#ffffff',
    },
  };
}

export function createNavigationTheme(
  colorScheme: 'dark' | 'light' | null | undefined,
  paperTheme: ReturnType<typeof createPaperTheme>,
): NavigationTheme {
  if (colorScheme === 'dark') {
    return {
      ...DarkTheme,
      colors: {
        ...DarkTheme.colors,
        primary: OPEN_LOCKER_PRIMARY,
        background: paperTheme.colors.background,
        card: paperTheme.colors.surface,
      },
    };
  }

  return {
    ...DefaultTheme,
    colors: {
      ...DefaultTheme.colors,
      primary: OPEN_LOCKER_PRIMARY,
      background: paperTheme.colors.background,
      card: paperTheme.colors.surface,
    },
  };
}
