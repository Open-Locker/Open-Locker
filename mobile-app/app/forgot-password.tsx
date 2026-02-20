import React from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet, View } from 'react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, HelperText, Text, TextInput, useTheme } from 'react-native-paper';

import { usePostPasswordEmailMutation } from '@/src/store/generatedApi';

function getErrorMessage(error: unknown): string {
  const apiError = error as FetchBaseQueryError | undefined;
  if (apiError && typeof apiError === 'object' && 'status' in apiError) {
    if (apiError.status === 422) {
      return 'Please enter a valid account email.';
    }
    return `Request failed (${String(apiError.status)}).`;
  }
  if (error instanceof Error) {
    return error.message;
  }
  return 'Something went wrong.';
}

export default function ForgotPasswordScreen() {
  const [requestPasswordReset] = usePostPasswordEmailMutation();
  const [email, setEmail] = React.useState('');
  const [error, setError] = React.useState<string | null>(null);
  const [successMessage, setSuccessMessage] = React.useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = React.useState(false);
  const theme = useTheme();

  const canSubmit = email.trim().length > 0 && !isSubmitting;

  const onSubmit = React.useCallback(async () => {
    setError(null);
    setSuccessMessage(null);
    setIsSubmitting(true);
    try {
      const res = await requestPasswordReset({
        sendPasswordResetRequest: { email: email.trim() },
      }).unwrap();
      setSuccessMessage(
        typeof res.message === 'string' ? res.message : 'Password reset link sent.',
      );
    } catch (e) {
      setError(getErrorMessage(e));
    } finally {
      setIsSubmitting(false);
    }
  }, [email, requestPasswordReset]);

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: theme.colors.background }]}>
      <KeyboardAvoidingView
        style={styles.container}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <Text variant="headlineSmall">Forgot Password</Text>
        <Text variant="bodyMedium" style={styles.subtitle}>
          Enter your account email and we will send you a reset link.
        </Text>

        <TextInput
          label="Email"
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          keyboardType="email-address"
        />

        <HelperText type="error" visible={!!error}>
          {error}
        </HelperText>
        <HelperText type="info" visible={!!successMessage}>
          {successMessage}
        </HelperText>

        <View style={styles.actions}>
          <Button mode="contained" onPress={onSubmit} disabled={!canSubmit} loading={isSubmitting}>
            Send reset link
          </Button>
          <Button mode="text" onPress={() => router.replace('/sign-in')}>
            Back to sign in
          </Button>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: {
    flex: 1,
    paddingHorizontal: 16,
    paddingTop: 24,
    gap: 8,
  },
  subtitle: {
    marginBottom: 12,
    opacity: 0.85,
  },
  actions: {
    marginTop: 8,
    gap: 8,
  },
});
