import type { MD3Theme } from 'react-native-paper';

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

  return {
    color: '#B7791F',
    borderColor: '#D69E2E',
    backgroundColor: '#FFF6E8',
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
      color: '#A34747',
      borderColor: '#E08A8A',
      backgroundColor: '#FFF3F3',
    };
  }

  return {
    color: theme.colors.onSurfaceVariant,
    borderColor: theme.colors.outlineVariant,
    backgroundColor: theme.colors.background,
  };
}
