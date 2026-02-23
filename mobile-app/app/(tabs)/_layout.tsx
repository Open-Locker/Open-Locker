import React from 'react';
import FontAwesome from '@expo/vector-icons/FontAwesome';
import { Tabs } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';
import { Banner, IconButton } from 'react-native-paper';

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

export default function TabLayout() {
  const colorScheme = useColorScheme();
  const dispatch = useAppDispatch();
  const { data: user } = useGetUserQuery();
  const [logoutCurrentSession] = usePostLogoutMutation();
  const [sendVerificationEmail, sendVerificationEmailState] =
    usePostEmailVerificationNotificationMutation();
  const needsVerification = !!user && !user.email_verified_at;
  const [verificationMessage, setVerificationMessage] = React.useState<string | null>(null);

  return (
    <View style={styles.container}>
      {needsVerification ? (
        <Banner
          visible
          actions={[
            {
              label: sendVerificationEmailState.isLoading ? 'Sending...' : 'Resend email',
              onPress: () => {
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
              },
            },
          ]}
        >
          Please verify your email address to unlock all features.
        </Banner>
      ) : null}
      {verificationMessage ? <Banner visible>{verificationMessage}</Banner> : null}
      <Tabs
        screenOptions={{
          tabBarActiveTintColor: OPEN_LOCKER_PRIMARY,
          // Disable the static render of the header on web
          // to prevent a hydration error in React Navigation v6.
          headerShown: useClientOnlyValue(false, true),
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
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
  },
});
