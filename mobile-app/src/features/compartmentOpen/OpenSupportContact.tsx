import { Phone } from 'lucide-react-native';
import { Linking, StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button, Text, useTheme } from 'react-native-paper';

import { toTelUrl } from './openProgress';

type Props = {
  phone: string;
};

export function OpenSupportContact({ phone }: Props) {
  const { t } = useTranslation();
  const theme = useTheme();

  return (
    <View style={styles.wrap}>
      <Text variant="bodyMedium">{t('compartments.openSupport.prompt')}</Text>
      <Button
        mode="outlined"
        icon={({ size, color }) => <Phone size={size} color={color} />}
        textColor={theme.colors.primary}
        onPress={() => {
          void Linking.openURL(toTelUrl(phone)).catch(() => undefined);
        }}
        accessibilityHint={t('compartments.openSupport.callHint')}
      >
        {t('compartments.openSupport.call', { phone })}
      </Button>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    gap: 8,
    paddingVertical: 6,
  },
});
