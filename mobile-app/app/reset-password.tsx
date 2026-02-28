import React from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet, View } from 'react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { router, useLocalSearchParams } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { HelperText, Text, useTheme } from 'react-native-paper';

import { usePostResetPasswordMutation } from '@/src/store/generatedApi';
import { OPEN_LOCKER_DESIGN_TOKENS } from '@/src/theme/tokens';
import { AppButton, AppTextInput } from '@/src/ui';

function toParamValue(value: string | string[] | undefined): string {
  if (Array.isArray(value)) {
    return value[0] ?? '';
  }
  return value ?? '';
}

function getErrorMessage(error: unknown): string {
  const apiError = error as FetchBaseQueryError | undefined;
  if (apiError && typeof apiError === 'object' && 'status' in apiError) {
    if (apiError.status === 422) {
      return 'Reset token is invalid or expired.';
    }
    return `Request failed (${String(apiError.status)}).`;
  }
  if (error instanceof Error) {
    return error.message;
  }
  return 'Something went wrong.';
}

export default function ResetPasswordScreen() {
  const params = useLocalSearchParams<{ token?: string; email?: string }>();
  const [resetPassword] = usePostResetPasswordMutation();
  const theme = useTheme();

  const [token, setToken] = React.useState(() => toParamValue(params.token));
  const [email, setEmail] = React.useState(() => toParamValue(params.email));
  const [password, setPassword] = React.useState('');
  const [passwordConfirmation, setPasswordConfirmation] = React.useState('');
  const [error, setError] = React.useState<string | null>(null);
  const [successMessage, setSuccessMessage] = React.useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = React.useState(false);

  const canSubmit =
    token.trim().length > 0 &&
    email.trim().length > 0 &&
    password.length > 0 &&
    passwordConfirmation.length > 0 &&
    !isSubmitting;

  const onSubmit = React.useCallback(async () => {
    setError(null);
    setSuccessMessage(null);
    setIsSubmitting(true);
    try {
      const res = await resetPassword({
        resetPasswordRequest: {
          token: token.trim(),
          email: email.trim(),
          password,
          password_confirmation: passwordConfirmation,
        },
      }).unwrap();
      setSuccessMessage(typeof res.message === 'string' ? res.message : 'Password has been reset.');
    } catch (e) {
      setError(getErrorMessage(e));
    } finally {
      setIsSubmitting(false);
    }
  }, [email, password, passwordConfirmation, resetPassword, token]);

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: theme.colors.background }]}>
      <KeyboardAvoidingView
        style={styles.container}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <Text variant="headlineSmall">Reset Password</Text>
        <Text variant="bodyMedium" style={styles.subtitle}>
          Paste your reset token and choose a new password.
        </Text>

        <AppTextInput label="Email" value={email} onChangeText={setEmail} autoCapitalize="none" />
        <AppTextInput
          label="Reset token"
          value={token}
          onChangeText={setToken}
          autoCapitalize="none"
        />
        <AppTextInput
          label="New password"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          textContentType="newPassword"
        />
        <AppTextInput
          label="Confirm new password"
          value={passwordConfirmation}
          onChangeText={setPasswordConfirmation}
          secureTextEntry
          textContentType="newPassword"
        />

        <HelperText type="error" visible={!!error}>
          {error}
        </HelperText>
        <HelperText type="info" visible={!!successMessage}>
          {successMessage}
        </HelperText>

        <View style={styles.actions}>
          <AppButton
            mode="contained"
            onPress={onSubmit}
            disabled={!canSubmit}
            loading={isSubmitting}
          >
            Reset password
          </AppButton>
          <AppButton mode="text" onPress={() => router.replace('/sign-in')}>
            Back to sign in
          </AppButton>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: {
    flex: 1,
    paddingHorizontal: OPEN_LOCKER_DESIGN_TOKENS.spacing.lg,
    paddingTop: 24,
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
  },
  subtitle: {
    marginBottom: 12,
    opacity: 0.85,
  },
  actions: {
    marginTop: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
  },
});
