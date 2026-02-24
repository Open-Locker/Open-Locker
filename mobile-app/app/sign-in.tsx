import React from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet, View } from 'react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { router, useFocusEffect } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, HelperText, Surface, Text, TextInput, useTheme } from 'react-native-paper';

import LogoOpenLocker from '@/assets/images/logo_open_locker.svg';
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
        <View style={styles.brandHeader}>
          <View style={styles.logoContainer}>
            <LogoOpenLocker width={58} height={58} />
          </View>
          <Text variant="headlineMedium" style={styles.title}>
            Open Locker
          </Text>
          <Text variant="titleSmall" style={styles.tagline}>
            Der smarte Schrank für alle
          </Text>
        </View>

        <Surface elevation={1} style={[styles.formCard, { backgroundColor: theme.colors.surface }]}>
          <Text variant="titleMedium" style={styles.formTitle}>
            Sign in
          </Text>
          <Text variant="bodyMedium" style={styles.subtitle}>
            Access compartments and manage your items.
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
            contentStyle={styles.buttonContent}
          >
            Sign in
          </Button>

          <View style={styles.linksRow}>
            <Button mode="text" onPress={() => router.push('/forgot-password' as never)} compact>
              Forgot password?
            </Button>
            <Button mode="text" onPress={() => router.push('/change-server' as never)} compact>
              Change server
            </Button>
          </View>
        </Surface>

        <Text variant="bodySmall" style={styles.currentServer}>
          Server: {currentApiBaseUrl}
        </Text>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: {
    flex: 1,
    paddingHorizontal: 20,
    paddingTop: 20,
    paddingBottom: 18,
    gap: 14,
  },
  brandHeader: {
    alignItems: 'center',
    gap: 6,
    marginBottom: 8,
  },
  logoContainer: {
    width: 86,
    height: 86,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#ffffff',
    borderWidth: 1,
    borderColor: '#e4eafc',
  },
  title: { fontWeight: '700', letterSpacing: 0.2, fontFamily: 'Inter_700Bold' },
  tagline: { opacity: 0.75, marginTop: 2, fontFamily: 'Inter_500Medium' },
  formCard: {
    borderRadius: 18,
    padding: 16,
    gap: 8,
  },
  formTitle: { fontWeight: '600', marginBottom: 2, fontFamily: 'Inter_600SemiBold' },
  subtitle: { opacity: 0.82, marginBottom: 8, lineHeight: 21 },
  input: { marginTop: 6 },
  button: { marginTop: 10, borderRadius: 12 },
  buttonContent: { height: 46 },
  linksRow: {
    marginTop: 6,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  currentServer: {
    opacity: 0.62,
    marginTop: 'auto',
    textAlign: 'center',
  },
});
