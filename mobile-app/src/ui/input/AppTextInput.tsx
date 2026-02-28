import React from 'react';
import { TextInput as PaperTextInput, useTheme } from 'react-native-paper';

type PaperTextInputProps = React.ComponentProps<typeof PaperTextInput>;

export function AppTextInput({
  mode = 'outlined',
  outlineColor,
  activeOutlineColor,
  ...props
}: PaperTextInputProps) {
  const theme = useTheme();

  return (
    <PaperTextInput
      mode={mode}
      outlineColor={outlineColor ?? theme.colors.outlineVariant}
      activeOutlineColor={activeOutlineColor ?? theme.colors.primary}
      {...props}
    />
  );
}
