import type { MD3Theme } from 'react-native-paper';

import {
  OPEN_LOCKER_DARK_WARNING,
  OPEN_LOCKER_DARK_WARNING_CONTAINER,
  OPEN_LOCKER_DARK_WARNING_OUTLINE,
  OPEN_LOCKER_LIGHT_WARNING,
  OPEN_LOCKER_LIGHT_WARNING_CONTAINER,
  OPEN_LOCKER_LIGHT_WARNING_OUTLINE,
} from '@/src/theme/tokens';

export type CompartmentVisualStatus = 'open' | 'closed' | 'unknown';
export type LockerVisualStatus = 'online' | 'offline';

type StatusPalette = {
  color: string;
  borderColor: string;
  backgroundColor: string;
};

export function getCompartmentStatusPalette(
  theme: MD3Theme,
  status: CompartmentVisualStatus,
): StatusPalette {
  if (status === 'open') {
    return {
      color: theme.colors.primary,
      borderColor: theme.colors.primary,
      backgroundColor: theme.colors.primaryContainer,
    };
  }

  if (status === 'closed') {
    return {
      color: theme.colors.onSurfaceVariant,
      borderColor: theme.colors.outline,
      backgroundColor: theme.colors.surfaceVariant,
    };
  }

  return theme.dark
    ? {
        color: OPEN_LOCKER_DARK_WARNING,
        borderColor: OPEN_LOCKER_DARK_WARNING_OUTLINE,
        backgroundColor: OPEN_LOCKER_DARK_WARNING_CONTAINER,
      }
    : {
        color: OPEN_LOCKER_LIGHT_WARNING,
        borderColor: OPEN_LOCKER_LIGHT_WARNING_OUTLINE,
        backgroundColor: OPEN_LOCKER_LIGHT_WARNING_CONTAINER,
      };
}

export function getLockerStatusPalette(
  theme: MD3Theme,
  status: LockerVisualStatus,
  selected: boolean,
): StatusPalette {
  if (selected) {
    return {
      color: theme.colors.onPrimaryContainer,
      borderColor: theme.colors.primary,
      backgroundColor: theme.colors.primaryContainer,
    };
  }

  if (status === 'offline') {
    return {
      color: theme.colors.error,
      borderColor: theme.colors.error,
      backgroundColor: theme.colors.errorContainer,
    };
  }

  return {
    color: theme.colors.onSurfaceVariant,
    borderColor: theme.colors.outlineVariant,
    backgroundColor: theme.colors.background,
  };
}
