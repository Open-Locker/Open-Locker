import { Phone } from 'lucide-react-native';
import { Linking } from 'react-native';
import { useTranslation } from 'react-i18next';

import { AppButton } from '@/src/ui';

import { toTelUrl } from './telUrl';

type Props = {
  phone: string;
};

export function SupportCallButton({ phone }: Props) {
  const { t } = useTranslation();

  return (
    <AppButton
      mode="outlined"
      icon={({ size, color }) => <Phone size={size} color={color} />}
      onPress={() => {
        void Linking.openURL(toTelUrl(phone)).catch(() => undefined);
      }}
      accessibilityHint={t('compartmentHelp.callHint')}
    >
      {t('compartmentHelp.call', { phone })}
    </AppButton>
  );
}
