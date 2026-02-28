import React from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet } from 'react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { useTranslation } from 'react-i18next';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, HelperText, Text, TextInput, useTheme } from 'react-native-paper';

import {
  getApiBaseUrl,
  normalizeApiBaseUrl,
  resetApiBaseUrlToDefault,
  setApiBaseUrl,
} from '@/src/api/baseUrl';
import { baseApi } from '@/src/store/baseApi';
import { useAppDispatch } from '@/src/store/hooks';

function getErrorMessage(
  error: unknown,
  t: (key: string, options?: Record<string, unknown>) => string,
): string {
  const apiError = error as FetchBaseQueryError | undefined;
  if (apiError && typeof apiError === 'object' && 'status' in apiError) {
    return t('common.requestFailedWithStatus', { status: String(apiError.status) });
  }
  if (error instanceof Error) {
    return error.message;
  }
  return t('common.somethingWentWrong');
}

export default function ChangeServerScreen() {
  const { t } = useTranslation();
  const dispatch = useAppDispatch();
  const theme = useTheme();

  const [customApiBaseUrl, setCustomApiBaseUrl] = React.useState(getApiBaseUrl());
  const [backendMessage, setBackendMessage] = React.useState<string | null>(null);
  const [backendError, setBackendError] = React.useState<string | null>(null);
  const [isSaving, setIsSaving] = React.useState(false);

  const onSaveBackendUrl = React.useCallback(async () => {
    setBackendError(null);
    setBackendMessage(null);

    const normalized = normalizeApiBaseUrl(customApiBaseUrl);
    if (!normalized) {
      setBackendError(t('server.enterValidBackendUrl'));
      return;
    }

    setIsSaving(true);
    try {
      const identifyResponse = await fetch(`${normalized}/identify`, {
        method: 'GET',
        headers: { Accept: 'application/json' },
      });
      if (!identifyResponse.ok) {
        setBackendError(
          t('server.backendCheckFailed', { status: String(identifyResponse.status) }),
        );
        return;
      }

      await setApiBaseUrl(normalized);
      dispatch(baseApi.util.resetApiState());
      setBackendMessage(t('server.backendUpdated'));
    } catch (error) {
      setBackendError(getErrorMessage(error, t));
    } finally {
      setIsSaving(false);
    }
  }, [customApiBaseUrl, dispatch, t]);

  const onResetBackendUrl = React.useCallback(async () => {
    setBackendError(null);
    setBackendMessage(null);
    await resetApiBaseUrlToDefault();
    setCustomApiBaseUrl(getApiBaseUrl());
    dispatch(baseApi.util.resetApiState());
    setBackendMessage(t('server.backendReset'));
  }, [dispatch, t]);

  return (
    <SafeAreaView
      style={[styles.safe, { backgroundColor: theme.colors.background }]}
      edges={['bottom']}
    >
      <KeyboardAvoidingView
        style={styles.container}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <Text variant="headlineSmall" style={styles.title}>
          {t('server.title')}
        </Text>
        <Text variant="bodyMedium" style={styles.subtitle}>
          {t('server.subtitle')}
        </Text>

        <TextInput
          label={t('server.apiBaseUrl')}
          value={customApiBaseUrl}
          onChangeText={setCustomApiBaseUrl}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="url"
          style={styles.input}
        />
        <Button mode="contained" onPress={() => void onSaveBackendUrl()} loading={isSaving}>
          {t('server.saveBackendUrl')}
        </Button>
        <Button mode="text" onPress={() => void onResetBackendUrl()}>
          {t('server.resetBackendUrl')}
        </Button>
        <HelperText type="error" visible={!!backendError}>
          {backendError}
        </HelperText>
        <HelperText type="info" visible={!!backendMessage}>
          {backendMessage}
        </HelperText>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: {
    flex: 1,
    paddingHorizontal: 16,
    paddingTop: 20,
    gap: 8,
  },
  title: { fontWeight: '700' },
  subtitle: { opacity: 0.8, marginBottom: 12 },
  input: { marginTop: 8 },
});
