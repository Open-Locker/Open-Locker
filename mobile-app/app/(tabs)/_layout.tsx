import React from 'react';
import FontAwesome from '@expo/vector-icons/FontAwesome';
import { router, Tabs } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';
import { ActivityIndicator, Button, IconButton, Surface, Text } from 'react-native-paper';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import Colors from '@/constants/Colors';
import { useColorScheme } from '@/components/useColorScheme';
import { baseApi } from '@/src/store/baseApi';
import { useClientOnlyValue } from '@/components/useClientOnlyValue';
import { OPEN_LOCKER_PRIMARY } from '@/src/config/theme';
import { clearPersistedAuth } from '@/src/store/authStorage';
import { clearCredentials } from '@/src/store/authSlice';
import {
  useGetUserQuery,
  usePostEmailVerificationNotificationMutation,
  usePostLogoutMutation,
} from '@/src/store/generatedApi';
import { useAppDispatch } from '@/src/store/hooks';

// You can explore the built-in icon families and icons on the web at https://icons.expo.fyi/
function TabBarIcon(props: {
  name: React.ComponentProps<typeof FontAwesome>['name'];
  color: string;
}) {
  return <FontAwesome size={28} style={{ marginBottom: -3 }} {...props} />;
}

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
  const colorScheme = useColorScheme();
  const headerShown = useClientOnlyValue(false, true);
  const insets = useSafeAreaInsets();
  const dispatch = useAppDispatch();
  const { data: user, isLoading: isLoadingUser } = useGetUserQuery();
  const [logoutCurrentSession] = usePostLogoutMutation();
  const [sendVerificationEmail, sendVerificationEmailState] =
    usePostEmailVerificationNotificationMutation();
  const needsTermsAcceptance = !!user && !user.terms_current_accepted;
  const needsVerification = !!user && !user.email_verified_at;
  const [verificationMessage, setVerificationMessage] = React.useState<string | null>(null);

  if (isLoadingUser) {
    return (
      <View style={styles.loadingContainer}>
        <ActivityIndicator />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Tabs
        screenOptions={{
          tabBarActiveTintColor: OPEN_LOCKER_PRIMARY,
          // Disable the static render of the header on web
          // to prevent a hydration error in React Navigation v6.
          headerShown,
        }}
      >
        <Tabs.Screen
          name="index"
          options={{
            title: 'Compartments',
            tabBarIcon: ({ color }) => <TabBarIcon name="th-large" color={color} />,
            headerRight: () => (
              <Pressable>
                {({ pressed }) => (
                  <IconButton
                    icon="logout"
                    size={22}
                    iconColor={Colors[colorScheme ?? 'light'].text}
                    style={{ opacity: pressed ? 0.5 : 1 }}
                    onPress={() => {
                      void (async () => {
                        try {
                          await logoutCurrentSession().unwrap();
                        } catch {
                          // ignore and clear local auth regardless
                        }
                        await clearPersistedAuth();
                        dispatch(baseApi.util.resetApiState());
                        dispatch(clearCredentials());
                      })();
                    }}
                    accessibilityLabel="Logout"
                  />
                )}
              </Pressable>
            ),
          }}
        />
        <Tabs.Screen
          name="two"
          options={{
            title: 'Account',
            tabBarIcon: ({ color }) => <TabBarIcon name="user" color={color} />,
          }}
        />
      </Tabs>
      <View
        pointerEvents="box-none"
        style={[styles.bottomBannerContainer, { bottom: 56 + insets.bottom }]}
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
});
