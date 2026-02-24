import { OPEN_LOCKER_PRIMARY_HEX } from '@/src/config/theme';

const tintColorLight = OPEN_LOCKER_PRIMARY_HEX;
const tintColorDark = OPEN_LOCKER_PRIMARY_HEX;

export default {
  light: {
    text: '#111827',
    background: '#f6f8fc',
    tint: tintColorLight,
    tabIconDefault: '#9ca3af',
    tabIconSelected: tintColorLight,
  },
  dark: {
    text: '#f9fafb',
    background: '#0b1020',
    tint: tintColorDark,
    tabIconDefault: '#9ca3af',
    tabIconSelected: tintColorDark,
  },
};
