import React from 'react';
import FontAwesome from '@expo/vector-icons/FontAwesome';
import { Tabs } from 'expo-router';
import { Pressable } from 'react-native';
import { IconButton } from 'react-native-paper';

import Colors from '@/constants/Colors';
import { useColorScheme } from '@/components/useColorScheme';
import { useClientOnlyValue } from '@/components/useClientOnlyValue';
import { OPEN_LOCKER_PRIMARY } from '@/src/config/theme';
import { clearPersistedAuth } from '@/src/store/authStorage';
import { clearCredentials } from '@/src/store/authSlice';
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

  return (
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
                      await clearPersistedAuth();
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
  );
}
