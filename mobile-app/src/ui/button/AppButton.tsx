import React from 'react';
import { StyleSheet } from 'react-native';
import { Button as PaperButton, useTheme } from 'react-native-paper';

import { OPEN_LOCKER_DESIGN_TOKENS } from '@/src/theme/tokens';

type PaperButtonProps = React.ComponentProps<typeof PaperButton>;

type AppButtonProps = PaperButtonProps & {
  compactHeight?: boolean;
};

export function AppButton({
  mode = 'contained',
  buttonColor,
  style,
  contentStyle,
  compactHeight = false,
  ...props
}: AppButtonProps) {
  const theme = useTheme();
  const isContained = mode === 'contained';

  return (
    <PaperButton
      mode={mode}
      buttonColor={buttonColor ?? (isContained ? theme.colors.primary : undefined)}
      style={[isContained && styles.containedButton, style]}
      contentStyle={[
        isContained && (compactHeight ? styles.containedContentCompact : styles.containedContent),
        contentStyle,
      ]}
      {...props}
    />
  );
}

const styles = StyleSheet.create({
  containedButton: {
    borderRadius: OPEN_LOCKER_DESIGN_TOKENS.radius.lg,
  },
  containedContent: {
    height: 48,
  },
  containedContentCompact: {
    height: 44,
  },
});
