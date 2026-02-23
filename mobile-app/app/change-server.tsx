import React from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet } from 'react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
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

function getErrorMessage(error: unknown): string {
  const apiError = error as FetchBaseQueryError | undefined;
  if (apiError && typeof apiError === 'object' && 'status' in apiError) {
    return `Request failed (${String(apiError.status)}).`;
  }
  if (error instanceof Error) {
    return error.message;
  }
  return 'Something went wrong.';
}

export default function ChangeServerScreen() {
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
      setBackendError('Please enter a valid backend URL.');
      return;
    }

    setIsSaving(true);
    try {
      const identifyResponse = await fetch(`${normalized}/identify`, {
        method: 'GET',
        headers: { Accept: 'application/json' },
      });
      if (!identifyResponse.ok) {
        setBackendError(`Backend check failed (${identifyResponse.status}).`);
        return;
      }

      await setApiBaseUrl(normalized);
      dispatch(baseApi.util.resetApiState());
      setBackendMessage('Backend updated. Return and sign in.');
    } catch (error) {
      setBackendError(getErrorMessage(error));
    } finally {
      setIsSaving(false);
    }
  }, [customApiBaseUrl, dispatch]);

  const onResetBackendUrl = React.useCallback(async () => {
    setBackendError(null);
    setBackendMessage(null);
    await resetApiBaseUrlToDefault();
    setCustomApiBaseUrl(getApiBaseUrl());
    dispatch(baseApi.util.resetApiState());
    setBackendMessage('Backend reset to default.');
  }, [dispatch]);

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
          Change server
        </Text>
        <Text variant="bodyMedium" style={styles.subtitle}>
          Select the backend URL before signing in.
        </Text>

        <TextInput
          label="API base URL"
          value={customApiBaseUrl}
          onChangeText={setCustomApiBaseUrl}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="url"
          style={styles.input}
        />
        <Button mode="contained" onPress={() => void onSaveBackendUrl()} loading={isSaving}>
          Save backend URL
        </Button>
        <Button mode="text" onPress={() => void onResetBackendUrl()}>
          Reset backend URL to default
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
