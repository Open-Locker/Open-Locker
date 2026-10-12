import React from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import { skipToken } from '@reduxjs/toolkit/query';
import { useLocalSearchParams } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { SafeAreaView } from 'react-native-safe-area-context';
import { HelperText, Text, useTheme } from 'react-native-paper';

import { SupportCallButton } from '@/src/features/compartmentHelp';
import { getApiErrorMessage } from '@/src/store/apiErrorMessage';
import {
  useGetCompartmentsAccessibleQuery,
  usePostCompartmentsByCompartmentHelpRequestsMutation,
} from '@/src/store/generatedApi';
import { useAppSelector } from '@/src/store/hooks';
import { OPEN_LOCKER_DESIGN_TOKENS } from '@/src/theme/tokens';
import { AppButton, AppTextInput } from '@/src/ui';

/** Match `CompartmentService::HELP_MESSAGE_MAX_LENGTH` and `CALLBACK_PHONE_MAX_LENGTH`. */
const HELP_MESSAGE_MAX_LENGTH = 1000;
const CALLBACK_PHONE_MAX_LENGTH = 32;

export default function CompartmentHelpScreen() {
  const { t } = useTranslation();
  const theme = useTheme();
  const token = useAppSelector((state) => state.auth.token);
  const { compartmentId } = useLocalSearchParams<{ compartmentId: string }>();
  const { data } = useGetCompartmentsAccessibleQuery(token ? {} : skipToken);
  const [sendHelpRequest, sendHelpRequestState] =
    usePostCompartmentsByCompartmentHelpRequestsMutation();
  const [message, setMessage] = React.useState('');
  const [phone, setPhone] = React.useState('');
  const [error, setError] = React.useState<string | null>(null);
  const [sent, setSent] = React.useState(false);

  const bank = data?.locker_banks.find((b) => b.compartments.some((c) => c.id === compartmentId));
  const compartment = bank?.compartments.find((c) => c.id === compartmentId);
  const supportPhone = bank?.support_phone?.trim() || null;
  const canSend = message.trim() !== '' && !sendHelpRequestState.isLoading && !sent;

  const send = async () => {
    if (!compartmentId) return;
    setError(null);
    try {
      await sendHelpRequest({
        compartment: compartmentId,
        requestCompartmentHelpRequest: { message: message.trim(), phone: phone.trim() || null },
      }).unwrap();
      setSent(true);
    } catch (e) {
      setError(getApiErrorMessage(e, t));
    }
  };

  return (
    <SafeAreaView
      style={[styles.safe, { backgroundColor: theme.colors.background }]}
      edges={['bottom']}
    >
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <Text variant="bodyMedium" style={styles.intro}>
          {compartment && bank
            ? t('compartmentHelp.intro', { number: compartment.number, bank: bank.name })
            : t('compartmentHelp.introUnknown')}
        </Text>

        {supportPhone ? (
          <View
            style={[
              styles.sectionCard,
              { backgroundColor: theme.colors.surface, borderColor: theme.colors.outlineVariant },
            ]}
          >
            <Text variant="titleSmall" style={styles.sectionTitle}>
              {t('compartmentHelp.callTitle')}
            </Text>
            <SupportCallButton phone={supportPhone} />
          </View>
        ) : null}

        <View
          style={[
            styles.sectionCard,
            { backgroundColor: theme.colors.surface, borderColor: theme.colors.outlineVariant },
          ]}
        >
          <Text variant="titleSmall" style={styles.sectionTitle}>
            {t('compartmentHelp.messageTitle')}
          </Text>
          <Text variant="bodySmall" style={styles.sectionDescription}>
            {t('compartmentHelp.messageDescription')}
          </Text>
          <AppTextInput
            value={message}
            onChangeText={setMessage}
            placeholder={t('compartmentHelp.messagePlaceholder')}
            multiline
            numberOfLines={5}
            maxLength={HELP_MESSAGE_MAX_LENGTH}
            editable={!sent}
            accessibilityLabel={t('compartmentHelp.messageTitle')}
          />
          {/* Marked as a phone number so the OS can offer the user's own number. */}
          <AppTextInput
            value={phone}
            onChangeText={setPhone}
            label={t('compartmentHelp.phoneLabel')}
            keyboardType="phone-pad"
            textContentType="telephoneNumber"
            autoComplete="tel"
            maxLength={CALLBACK_PHONE_MAX_LENGTH}
            editable={!sent}
          />
          <HelperText type="error" visible={!!error}>
            {error}
          </HelperText>
          {sent ? (
            <Text
              variant="bodyMedium"
              style={{ color: theme.colors.primary }}
              accessibilityRole="alert"
            >
              {t('compartmentHelp.sent')}
            </Text>
          ) : (
            <AppButton
              onPress={() => void send()}
              loading={sendHelpRequestState.isLoading}
              disabled={!canSend}
            >
              {t('compartmentHelp.send')}
            </AppButton>
          )}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: {
    padding: OPEN_LOCKER_DESIGN_TOKENS.spacing.lg,
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.md,
  },
  intro: { opacity: 0.8 },
  sectionCard: {
    borderWidth: 1,
    borderRadius: OPEN_LOCKER_DESIGN_TOKENS.radius.md,
    padding: OPEN_LOCKER_DESIGN_TOKENS.spacing.md,
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
  },
  sectionTitle: { fontFamily: 'Inter_600SemiBold' },
  sectionDescription: { opacity: 0.72 },
});
