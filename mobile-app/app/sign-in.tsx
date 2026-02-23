import React from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet } from 'react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { router, useFocusEffect } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, HelperText, Text, TextInput, useTheme } from 'react-native-paper';

import { OPEN_LOCKER_PRIMARY } from '@/src/config/theme';
import { getApiBaseUrl } from '@/src/api/baseUrl';
import { persistAuth } from '@/src/store/authStorage';
import { setCredentials } from '@/src/store/authSlice';
import { openLockerApi, usePostLoginMutation } from '@/src/store/generatedApi';
import { useAppDispatch } from '@/src/store/hooks';

function getErrorMessage(error: unknown): string {
  const apiError = error as FetchBaseQueryError | undefined;
  if (apiError && typeof apiError === 'object' && 'status' in apiError) {
    if (apiError.status === 422) {
      return 'Invalid email or password.';
    }
    return `Request failed (${String(apiError.status)}).`;
  }
  if (error instanceof Error) return error.message;
  return 'Something went wrong.';
}

export default function SignInScreen() {
  const dispatch = useAppDispatch();
  const [postLogin] = usePostLoginMutation();
  const theme = useTheme();

  const [email, setEmail] = React.useState('');
  const [password, setPassword] = React.useState('');
  const [isSubmitting, setIsSubmitting] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [currentApiBaseUrl, setCurrentApiBaseUrl] = React.useState(getApiBaseUrl());

  useFocusEffect(
    React.useCallback(() => {
      setCurrentApiBaseUrl(getApiBaseUrl());
    }, []),
  );

  const canSubmit = email.trim().length > 0 && password.length > 0 && !isSubmitting;

  const onSubmit = React.useCallback(async () => {
    setError(null);
    setIsSubmitting(true);
    try {
      const res = await postLogin({
        loginRequest: {
          email: email.trim(),
          password,
        },
      }).unwrap();

      await persistAuth(res.token, res.name);
      dispatch(setCredentials({ token: res.token, userName: res.name }));

      const userRequest = dispatch(openLockerApi.endpoints.getUser.initiate());
      const user = await userRequest.unwrap();
      userRequest.unsubscribe();
      if (!user.terms_current_accepted) {
        router.replace('/terms' as never);
      }
    } catch (e) {
      setError(getErrorMessage(e));
    } finally {
      setIsSubmitting(false);
    }
  }, [dispatch, email, password, postLogin]);

  return (
    <SafeAreaView
      style={[styles.safe, { backgroundColor: theme.colors.background }]}
      edges={['top', 'bottom']}
    >
      <KeyboardAvoidingView
        style={styles.container}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <Text variant="headlineMedium" style={styles.title}>
          Open Locker
        </Text>
        <Text variant="bodyMedium" style={styles.subtitle}>
          Sign in to view compartments and items.
        </Text>
        <Text variant="bodySmall" style={styles.currentServer}>
          Server: {currentApiBaseUrl}
        </Text>

        <TextInput
          label="Email"
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="email-address"
          textContentType="username"
          style={styles.input}
        />

        <TextInput
          label="Password"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          textContentType="password"
          style={styles.input}
        />

        <HelperText type="error" visible={!!error}>
          {error}
        </HelperText>

        <Button
          mode="contained"
          onPress={onSubmit}
          disabled={!canSubmit}
          loading={isSubmitting}
          buttonColor={OPEN_LOCKER_PRIMARY}
          style={styles.button}
        >
          Sign in
        </Button>

        <Button
          mode="text"
          onPress={() => router.push('/forgot-password' as never)}
          style={styles.linkButton}
        >
          Forgot password?
        </Button>
        <Button
          mode="text"
          onPress={() => router.push('/change-server' as never)}
          style={styles.linkButton}
        >
          Change server
        </Button>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: {
    flex: 1,
    paddingHorizontal: 16,
    paddingTop: 28,
    gap: 8,
  },
  title: { fontWeight: '700' },
  subtitle: { opacity: 0.8, marginBottom: 12 },
  currentServer: { opacity: 0.7, marginBottom: 8 },
  input: { marginTop: 8 },
  button: { marginTop: 12 },
  linkButton: { marginTop: 4, alignSelf: 'flex-start' },
});
