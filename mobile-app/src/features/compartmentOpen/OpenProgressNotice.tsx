import { CircleAlert, LockOpen } from 'lucide-react-native';
import { StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { ActivityIndicator, Text, useTheme } from 'react-native-paper';

import { openProgressTone, type OpenProgress } from './openProgress';

type Props = {
  progress: OpenProgress;
};

export function OpenProgressNotice({ progress }: Props) {
  const { t } = useTranslation();
  const theme = useTheme();
  const tone = openProgressTone(progress);
  const color =
    tone === 'success'
      ? theme.colors.primary
      : tone === 'problem'
        ? theme.colors.error
        : theme.colors.onSurfaceVariant;

  return (
    <View
      style={styles.row}
      accessibilityLiveRegion="polite"
      accessibilityRole={tone === 'problem' ? 'alert' : undefined}
    >
      {tone === 'pending' ? (
        <ActivityIndicator size={16} color={color} />
      ) : tone === 'success' ? (
        <LockOpen size={16} color={color} />
      ) : (
        <CircleAlert size={16} color={color} />
      )}
      <Text variant="bodyMedium" style={[styles.text, { color }]}>
        {t(`compartments.openProgress.${progress}`)}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 8,
    paddingVertical: 6,
  },
  text: {
    flex: 1,
  },
});
