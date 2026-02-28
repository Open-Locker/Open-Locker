import React from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  StyleSheet,
  View,
  useWindowDimensions,
} from 'react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import Constants from 'expo-constants';
import { router, useFocusEffect } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { HelperText, Text, useTheme } from 'react-native-paper';

import LogoOpenLocker from '@/assets/images/logo_open_locker.svg';
import { getApiBaseUrl } from '@/src/api/baseUrl';
import { persistAuth } from '@/src/store/authStorage';
import { setCredentials } from '@/src/store/authSlice';
import { openLockerApi, useIdentifyQuery, usePostLoginMutation } from '@/src/store/generatedApi';
import { useAppDispatch } from '@/src/store/hooks';
import { OPEN_LOCKER_DESIGN_TOKENS } from '@/src/theme/tokens';
import { AppButton, AppTextInput } from '@/src/ui';

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
  const { height } = useWindowDimensions();
  const [postLogin] = usePostLoginMutation();
  const {
    data: identifyData,
    isLoading: isLoadingIdentify,
    isError: isIdentifyError,
  } = useIdentifyQuery();
  const theme = useTheme();

  const [email, setEmail] = React.useState('');
  const [password, setPassword] = React.useState('');
  const [isSubmitting, setIsSubmitting] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [currentApiBaseUrl, setCurrentApiBaseUrl] = React.useState(getApiBaseUrl());
  const appVersion = Constants.expoConfig?.version ?? Constants.nativeAppVersion ?? 'unknown';
  const isCompactLayout = height <= 720;
  const serverVersion = isIdentifyError
    ? 'unreachable'
    : (identifyData?.version ?? (isLoadingIdentify ? 'loading...' : 'unknown'));

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
        style={[styles.container, isCompactLayout && styles.containerCompact]}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <View style={[styles.brandHeader, isCompactLayout && styles.brandHeaderCompact]}>
          <View
            style={[
              styles.logoContainer,
              isCompactLayout && styles.logoContainerCompact,
              {
                backgroundColor: theme.colors.surface,
                borderColor: theme.colors.outlineVariant,
              },
            ]}
          >
            <LogoOpenLocker width={58} height={58} />
          </View>
          <Text variant="headlineMedium" style={styles.title}>
            Open Locker
          </Text>
          <Text variant="titleSmall" style={styles.tagline}>
            Der smarte Schrank für alle
          </Text>
        </View>

        <View style={[styles.formWrap, isCompactLayout && styles.formWrapCompact]}>
          <Text variant="titleMedium" style={styles.formTitle}>
            Sign in
          </Text>
          <Text variant="bodyMedium" style={styles.subtitle}>
            Access compartments and manage your items.
          </Text>

          <AppTextInput
            label="Email"
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            textContentType="username"
            style={styles.input}
          />

          <AppTextInput
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

          <AppButton
            mode="contained"
            onPress={onSubmit}
            disabled={!canSubmit}
            loading={isSubmitting}
            style={styles.button}
            compactHeight={isCompactLayout}
          >
            Sign in
          </AppButton>

          <View style={styles.linksRow}>
            <AppButton mode="text" onPress={() => router.push('/forgot-password' as never)} compact>
              Forgot password?
            </AppButton>
            <AppButton mode="text" onPress={() => router.push('/change-server' as never)} compact>
              Change server
            </AppButton>
          </View>
        </View>

        <View style={[styles.metaBlock, isCompactLayout && styles.metaBlockCompact]}>
          <Text
            variant="bodySmall"
            style={[styles.currentServer, isCompactLayout && styles.currentServerCompact]}
          >
            Server: {currentApiBaseUrl}
          </Text>
          <Text
            variant="bodySmall"
            style={[styles.currentServer, isCompactLayout && styles.currentServerCompact]}
          >
            App version: {appVersion}
          </Text>
          <Text
            variant="bodySmall"
            style={[styles.currentServer, isCompactLayout && styles.currentServerCompact]}
          >
            Server version: {serverVersion}
          </Text>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: {
    flex: 1,
    paddingHorizontal: OPEN_LOCKER_DESIGN_TOKENS.spacing.xl,
    paddingTop: 28,
    paddingBottom: 20,
    gap: 18,
  },
  containerCompact: {
    paddingHorizontal: 20,
    paddingTop: 14,
    paddingBottom: 14,
    gap: 12,
  },
  brandHeader: {
    alignItems: 'center',
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
    marginBottom: 6,
  },
  brandHeaderCompact: {
    gap: 5,
    marginBottom: 2,
  },
  logoContainer: {
    width: 90,
    height: 90,
    borderRadius: OPEN_LOCKER_DESIGN_TOKENS.radius.xl,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
  },
  logoContainerCompact: {
    width: 72,
    height: 72,
    borderRadius: 18,
  },
  title: { fontWeight: '700', letterSpacing: 0.2, fontFamily: 'Inter_700Bold' },
  tagline: { opacity: 0.72, marginTop: 2, fontFamily: 'Inter_500Medium' },
  formWrap: {
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm + 2,
  },
  formWrapCompact: {
    gap: 7,
  },
  formTitle: {
    fontWeight: '600',
    marginBottom: 2,
    fontFamily: 'Inter_600SemiBold',
    textAlign: 'center',
  },
  subtitle: { opacity: 0.76, marginBottom: 10, lineHeight: 21, textAlign: 'center' },
  input: { marginTop: 4 },
  button: { marginTop: OPEN_LOCKER_DESIGN_TOKENS.spacing.md },
  linksRow: {
    marginTop: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  metaBlock: {
    marginTop: 'auto',
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.xs - 2,
  },
  metaBlockCompact: {
    gap: 1,
  },
  currentServer: {
    opacity: 0.62,
    textAlign: 'center',
  },
  currentServerCompact: {
    opacity: 0.56,
  },
});
