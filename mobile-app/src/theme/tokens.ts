export const OPEN_LOCKER_PRIMARY = 'hsla(223, 100%, 57%, 1)';
export const OPEN_LOCKER_PRIMARY_HEX = '#245CFF';

export const OPEN_LOCKER_LIGHT_BACKGROUND = '#f6f8fc';
export const OPEN_LOCKER_LIGHT_SURFACE = '#ffffff';
export const OPEN_LOCKER_LIGHT_SURFACE_VARIANT = '#f1f4f9';
export const OPEN_LOCKER_LIGHT_OUTLINE = '#bdc8dd';

export const OPEN_LOCKER_DARK_BACKGROUND = '#0b1020';
export const OPEN_LOCKER_DARK_SURFACE = '#111938';
export const OPEN_LOCKER_DARK_SURFACE_VARIANT = '#1a2240';
export const OPEN_LOCKER_DARK_OUTLINE = '#4f66c8';

// Warning (amber). MD3 has no semantic "warning" slot, so we define our own
// light/dark pair for status surfaces such as the "unknown" compartment badge.
export const OPEN_LOCKER_LIGHT_WARNING = '#B7791F';
export const OPEN_LOCKER_LIGHT_WARNING_OUTLINE = '#D69E2E';
export const OPEN_LOCKER_LIGHT_WARNING_CONTAINER = '#FFF6E8';

export const OPEN_LOCKER_DARK_WARNING = '#F2C66B';
export const OPEN_LOCKER_DARK_WARNING_OUTLINE = '#8A6D2E';
export const OPEN_LOCKER_DARK_WARNING_CONTAINER = '#3A2E12';

export const OPEN_LOCKER_DESIGN_TOKENS = {
  radius: {
    sm: 8,
    md: 12,
    lg: 16,
    xl: 22,
    pill: 999,
  },
  spacing: {
    xs: 4,
    sm: 8,
    md: 12,
    lg: 16,
    xl: 24,
  },
} as const;
