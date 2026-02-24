import React from 'react';
import { router, Stack } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';
import { ChevronRight } from 'lucide-react-native';
import { ActivityIndicator, Button, Surface, Text, useTheme } from 'react-native-paper';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useClientOnlyValue } from '@/components/useClientOnlyValue';
import {
  useGetUserQuery,
  usePostEmailVerificationNotificationMutation,
} from '@/src/store/generatedApi';

function SmallBottomNotice({
  message,
  actionLabel,
  onAction,
  actionLoading = false,
}: {
  message: string;
  actionLabel?: string;
  onAction?: () => void;
  actionLoading?: boolean;
}) {
  return (
    <Surface elevation={2} style={styles.noticeSurface}>
      <Text variant="bodySmall" style={styles.noticeText}>
        {message}
      </Text>
      {actionLabel && onAction ? (
        <Button compact mode="text" onPress={onAction} loading={actionLoading}>
          {actionLabel}
        </Button>
      ) : null}
    </Surface>
  );
}

export default function TabLayout() {
  const theme = useTheme();
  const headerShown = useClientOnlyValue(false, true);
  const insets = useSafeAreaInsets();
  const { data: user, isLoading: isLoadingUser } = useGetUserQuery();
  const [sendVerificationEmail, sendVerificationEmailState] =
    usePostEmailVerificationNotificationMutation();
  const needsTermsAcceptance = !!user && !user.terms_current_accepted;
  const needsVerification = !!user && !user.email_verified_at;
  const [verificationMessage, setVerificationMessage] = React.useState<string | null>(null);
  const accountInitial = (user?.name?.trim().charAt(0) || 'A').toUpperCase();

  if (isLoadingUser) {
    return (
      <View style={styles.loadingContainer}>
        <ActivityIndicator />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Stack
        screenOptions={{
          headerShown,
        }}
      >
        <Stack.Screen
          name="index"
          options={{
            title: 'Compartments',
            headerRight: () => (
              <View style={styles.headerActions}>
                <Pressable
                  style={({ pressed }) => [
                    styles.headerAccountButton,
                    pressed && styles.headerPressed,
                  ]}
                  onPress={() => router.push('/account' as never)}
                  accessibilityRole="button"
                  accessibilityLabel="Account"
                >
                  <View
                    style={[
                      styles.headerAvatarCircle,
                      { backgroundColor: theme.colors.primaryContainer },
                    ]}
                  >
                    <Text variant="labelMedium" style={styles.headerAvatarInitial}>
                      {accountInitial}
                    </Text>
                  </View>
                  <ChevronRight color={theme.colors.onSurfaceVariant} size={14} strokeWidth={2.2} />
                </Pressable>
              </View>
            ),
          }}
        />
      </Stack>
      <View
        pointerEvents="box-none"
        style={[styles.bottomBannerContainer, { bottom: 12 + insets.bottom }]}
      >
        {needsTermsAcceptance ? (
          <SmallBottomNotice
            message="New terms are available."
            actionLabel="Review"
            onAction={() => router.push('/terms' as never)}
          />
        ) : null}
        {needsVerification ? (
          <SmallBottomNotice
            message="Please verify your email."
            actionLabel={sendVerificationEmailState.isLoading ? 'Sending...' : 'Resend'}
            actionLoading={sendVerificationEmailState.isLoading}
            onAction={() => {
              void (async () => {
                try {
                  const response = await sendVerificationEmail().unwrap();
                  setVerificationMessage(
                    response && typeof response === 'object' && 'message' in response
                      ? String(response.message)
                      : 'Verification email sent.',
                  );
                } catch {
                  setVerificationMessage('Failed to send verification email.');
                }
              })();
            }}
          />
        ) : null}
        {verificationMessage ? <SmallBottomNotice message={verificationMessage} /> : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
  },
  bottomBannerContainer: {
    position: 'absolute',
    left: 0,
    right: 0,
    zIndex: 20,
    gap: 6,
    paddingHorizontal: 10,
  },
  noticeSurface: {
    borderRadius: 12,
    paddingHorizontal: 10,
    paddingVertical: 8,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
  },
  noticeText: {
    flex: 1,
    opacity: 0.9,
  },
  loadingContainer: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  headerActions: {
    flexDirection: 'row',
    alignItems: 'center',
    marginRight: 8,
    gap: 6,
  },
  headerAccountButton: {
    height: 34,
    paddingHorizontal: 2,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
  },
  headerAvatarCircle: {
    width: 22,
    height: 22,
    borderRadius: 11,
    alignItems: 'center',
    justifyContent: 'center',
  },
  headerAvatarInitial: {
    fontFamily: 'Inter_600SemiBold',
    fontSize: 11,
  },
  headerPressed: {
    opacity: 0.65,
  },
});
