import React from 'react';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { router } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { ScrollView, StyleSheet, useWindowDimensions, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ActivityIndicator, HelperText, Surface, Text, useTheme } from 'react-native-paper';
import RenderHtml from 'react-native-render-html';

import { baseApi } from '@/src/store/baseApi';
import { clearPersistedAuth } from '@/src/store/authStorage';
import { clearCredentials } from '@/src/store/authSlice';
import {
  openLockerApi,
  useGetTermsCurrentQuery,
  useGetUserQuery,
  usePostLogoutMutation,
  usePostTermsAcceptMutation,
} from '@/src/store/generatedApi';
import { useAppDispatch } from '@/src/store/hooks';
import { OPEN_LOCKER_DESIGN_TOKENS } from '@/src/theme/tokens';
import { AppButton } from '@/src/ui';

function getErrorMessage(
  error: unknown,
  t: (key: string, options?: Record<string, unknown>) => string,
): string {
  const apiError = error as FetchBaseQueryError | undefined;
  if (apiError && typeof apiError === 'object' && 'status' in apiError) {
    return t('common.requestFailedWithStatus', { status: String(apiError.status) });
  }
  if (error instanceof Error) return error.message;
  return t('common.somethingWentWrong');
}

function isCurrentAccepted(value: unknown): boolean {
  if (typeof value === 'boolean') return value;
  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase();
    return normalized === 'true' || normalized === '1' || normalized === 'yes';
  }
  return false;
}

export default function TermsScreen() {
  const { t } = useTranslation();
  const theme = useTheme();
  const { width } = useWindowDimensions();
  const dispatch = useAppDispatch();
  const [acceptTerms, acceptTermsState] = usePostTermsAcceptMutation();
  const [logoutCurrentSession] = usePostLogoutMutation();
  const { data: user, isLoading: isLoadingUser } = useGetUserQuery();
  const {
    data: currentTerms,
    isLoading: isLoadingTerms,
    error: termsError,
  } = useGetTermsCurrentQuery();
  const [submitError, setSubmitError] = React.useState<string | null>(null);

  const clearSession = React.useCallback(async () => {
    await clearPersistedAuth();
    dispatch(baseApi.util.resetApiState());
    dispatch(clearCredentials());
  }, [dispatch]);

  const hasAcceptedCurrentTerms = !!user?.terms_current_accepted;
  const termsAlreadyAccepted =
    hasAcceptedCurrentTerms || isCurrentAccepted(currentTerms?.current_accepted);

  React.useEffect(() => {
    if (!isLoadingUser && termsAlreadyAccepted) {
      router.replace('/(tabs)' as never);
    }
  }, [isLoadingUser, termsAlreadyAccepted]);

  const onAccept = React.useCallback(async () => {
    setSubmitError(null);
    try {
      await acceptTerms().unwrap();
      dispatch(openLockerApi.util.invalidateTags(['Auth', 'Terms']));
      router.replace('/(tabs)' as never);
    } catch (error) {
      setSubmitError(getErrorMessage(error, t));
    }
  }, [acceptTerms, dispatch, t]);

  const onLogout = React.useCallback(async () => {
    try {
      await logoutCurrentSession().unwrap();
    } catch {
      // ignore and clear local auth regardless
    } finally {
      await clearSession();
    }
  }, [clearSession, logoutCurrentSession]);

  const onClose = React.useCallback(() => {
    router.replace('/(tabs)' as never);
  }, []);

  if (isLoadingUser || isLoadingTerms) {
    return (
      <SafeAreaView
        style={[styles.safe, { backgroundColor: theme.colors.background }]}
        edges={['bottom']}
      >
        <View style={styles.loadingWrap}>
          <ActivityIndicator />
          <Text style={styles.loadingText}>{t('terms.loadingCurrent')}</Text>
        </View>
      </SafeAreaView>
    );
  }

  const termsErrorMessage =
    termsError && 'status' in termsError
      ? t('terms.loadFailed', { status: String(termsError.status) })
      : null;

  return (
    <SafeAreaView
      style={[styles.safe, { backgroundColor: theme.colors.background }]}
      edges={['bottom']}
    >
      <ScrollView contentContainerStyle={styles.container}>
        <Text variant="headlineSmall" style={styles.title}>
          {t('navigation.terms')}
        </Text>
        <Text variant="bodyMedium" style={styles.subtitle}>
          {t('terms.mustAccept')}
        </Text>

        <Surface
          style={[styles.contentCard, { backgroundColor: theme.colors.surface }]}
          elevation={1}
        >
          <Text variant="titleSmall">
            {currentTerms?.document_name ?? t('terms.currentTerms')}
            {currentTerms?.version ? ` v${currentTerms.version}` : ''}
          </Text>
          {currentTerms?.published_at ? (
            <Text variant="bodySmall" style={styles.metaText}>
              {t('terms.publishedAt', {
                value: new Date(currentTerms.published_at).toLocaleString(),
              })}
            </Text>
          ) : null}
          {currentTerms?.content ? (
            <RenderHtml
              contentWidth={Math.max(0, width - 56)}
              source={{ html: currentTerms.content }}
              baseStyle={{
                color: theme.colors.onSurface,
                fontSize: 16,
                lineHeight: 24,
              }}
              tagsStyles={{
                p: { marginTop: 0, marginBottom: 12 },
                h1: { fontSize: 24, lineHeight: 32, marginTop: 0, marginBottom: 12 },
                h2: { fontSize: 20, lineHeight: 28, marginTop: 0, marginBottom: 12 },
                li: { marginBottom: 8 },
              }}
            />
          ) : (
            <Text variant="bodyMedium" style={styles.contentText}>
              {t('terms.noContent')}
            </Text>
          )}
        </Surface>

        <HelperText type="error" visible={!!termsErrorMessage}>
          {termsErrorMessage}
        </HelperText>
        <HelperText type="error" visible={!!submitError}>
          {submitError}
        </HelperText>

        <AppButton
          mode="contained"
          onPress={() => void onAccept()}
          loading={acceptTermsState.isLoading}
          disabled={acceptTermsState.isLoading || !!termsErrorMessage || !currentTerms}
          style={styles.acceptButton}
        >
          {t('terms.acceptAndContinue')}
        </AppButton>
        <AppButton mode="text" onPress={onClose}>
          {t('common.close')}
        </AppButton>
        <AppButton mode="text" onPress={() => void onLogout()}>
          {t('account.logout')}
        </AppButton>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: {
    paddingHorizontal: OPEN_LOCKER_DESIGN_TOKENS.spacing.lg,
    paddingTop: OPEN_LOCKER_DESIGN_TOKENS.spacing.lg,
    paddingBottom: OPEN_LOCKER_DESIGN_TOKENS.spacing.xl,
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
  },
  title: { fontWeight: '700' },
  subtitle: { opacity: 0.8, marginBottom: 8 },
  contentCard: {
    padding: OPEN_LOCKER_DESIGN_TOKENS.spacing.md,
    borderRadius: OPEN_LOCKER_DESIGN_TOKENS.radius.md,
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
  },
  metaText: { opacity: 0.7 },
  contentText: { lineHeight: 22 },
  acceptButton: { marginTop: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm },
  loadingWrap: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    padding: 24,
  },
  loadingText: { opacity: 0.7 },
});
