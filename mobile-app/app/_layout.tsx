import 'react-native-gesture-handler';
import {
  Inter_400Regular,
  Inter_500Medium,
  Inter_600SemiBold,
  Inter_700Bold,
} from '@expo-google-fonts/inter';
import { BottomSheetModalProvider } from '@gorhom/bottom-sheet';
import { DarkTheme, DefaultTheme, ThemeProvider } from '@react-navigation/native';
import { useFonts } from 'expo-font';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { useEffect } from 'react';
import 'react-native-reanimated';
import { Provider } from 'react-redux';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { MD3DarkTheme, MD3LightTheme, PaperProvider } from 'react-native-paper';

import { useColorScheme } from '@/components/useColorScheme';
import { hydrateApiBaseUrl } from '@/src/api/baseUrl';
import {
  OPEN_LOCKER_DARK_BACKGROUND,
  OPEN_LOCKER_DARK_OUTLINE,
  OPEN_LOCKER_DARK_SURFACE,
  OPEN_LOCKER_DARK_SURFACE_VARIANT,
  OPEN_LOCKER_LIGHT_BACKGROUND,
  OPEN_LOCKER_LIGHT_OUTLINE,
  OPEN_LOCKER_LIGHT_SURFACE,
  OPEN_LOCKER_LIGHT_SURFACE_VARIANT,
  OPEN_LOCKER_PRIMARY,
} from '@/src/config/theme';
import { loadPersistedAuth } from '@/src/store/authStorage';
import { restoreAuth } from '@/src/store/authSlice';
import { useAppDispatch, useAppSelector } from '@/src/store/hooks';
import { store } from '@/src/store/store';

export {
  // Catch any errors thrown by the Layout component.
  ErrorBoundary,
} from 'expo-router';

export const unstable_settings = {
  // Ensure that reloading on `/modal` keeps a back button present.
  initialRouteName: '(tabs)',
};

// Prevent the splash screen from auto-hiding before asset loading is complete.
SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <Provider store={store}>
        <RootLayoutBootstrap />
      </Provider>
    </GestureHandlerRootView>
  );
}

function RootLayoutBootstrap() {
  const dispatch = useAppDispatch();
  const [loaded, error] = useFonts({
    SpaceMono: require('../assets/fonts/SpaceMono-Regular.ttf'),
    Inter_400Regular,
    Inter_500Medium,
    Inter_600SemiBold,
    Inter_700Bold,
  });

  useEffect(() => {
    let cancelled = false;
    (async () => {
      await hydrateApiBaseUrl();
      const auth = await loadPersistedAuth();
      if (!cancelled) {
        dispatch(restoreAuth(auth));
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [dispatch]);

  // Expo Router uses Error Boundaries to catch errors in the navigation tree.
  useEffect(() => {
    if (error) throw error;
  }, [error]);

  useEffect(() => {
    if (loaded) {
      SplashScreen.hideAsync();
    }
  }, [loaded]);

  if (!loaded) {
    return null;
  }

  return <RootLayoutNav />;
}

function RootLayoutNav() {
  const colorScheme = useColorScheme();
  const token = useAppSelector((state) => state.auth.token);
  const isLoading = useAppSelector((state) => state.auth.isLoading);

  if (isLoading) {
    return null;
  }

  const paperTheme =
    colorScheme === 'dark'
      ? {
          ...MD3DarkTheme,
          colors: {
            ...MD3DarkTheme.colors,
            primary: OPEN_LOCKER_PRIMARY,
            onPrimary: '#ffffff',
            primaryContainer: '#243a86',
            onPrimaryContainer: '#dbe4ff',
            secondary: '#9fb6ff',
            onSecondary: '#0f1a40',
            secondaryContainer: '#1b264f',
            onSecondaryContainer: '#d8e1ff',
            tertiary: '#9cb5ff',
            onTertiary: '#121f47',
            tertiaryContainer: '#202d5c',
            onTertiaryContainer: '#d7e1ff',
            background: OPEN_LOCKER_DARK_BACKGROUND,
            onBackground: '#edf1ff',
            surface: OPEN_LOCKER_DARK_SURFACE,
            onSurface: '#edf1ff',
            surfaceVariant: OPEN_LOCKER_DARK_SURFACE_VARIANT,
            onSurfaceVariant: '#b8c3e8',
            outline: OPEN_LOCKER_DARK_OUTLINE,
            outlineVariant: '#384790',
          },
          elevation: {
            level0: OPEN_LOCKER_DARK_SURFACE,
            level1: '#121d3f',
            level2: '#14234c',
            level3: '#172a59',
            level4: '#1a3066',
            level5: '#1d3773',
          },
        }
      : {
          ...MD3LightTheme,
          colors: {
            ...MD3LightTheme.colors,
            primary: OPEN_LOCKER_PRIMARY,
            onPrimary: '#ffffff',
            primaryContainer: '#dce5ff',
            onPrimaryContainer: '#0f2a75',
            secondary: '#51689b',
            onSecondary: '#ffffff',
            secondaryContainer: '#e2e7f4',
            onSecondaryContainer: '#1f2c4d',
            tertiary: '#405f92',
            onTertiary: '#ffffff',
            tertiaryContainer: '#d8e5ff',
            onTertiaryContainer: '#102848',
            background: OPEN_LOCKER_LIGHT_BACKGROUND,
            onBackground: '#111827',
            surface: OPEN_LOCKER_LIGHT_SURFACE,
            onSurface: '#111827',
            surfaceVariant: OPEN_LOCKER_LIGHT_SURFACE_VARIANT,
            onSurfaceVariant: '#4a587c',
            outline: OPEN_LOCKER_LIGHT_OUTLINE,
            outlineVariant: '#d6defb',
          },
          elevation: {
            level0: OPEN_LOCKER_LIGHT_SURFACE,
            level1: '#ffffff',
            level2: '#ffffff',
            level3: '#ffffff',
            level4: '#ffffff',
            level5: '#ffffff',
          },
        };

  const navigationTheme =
    colorScheme === 'dark'
      ? {
          ...DarkTheme,
          colors: {
            ...DarkTheme.colors,
            primary: OPEN_LOCKER_PRIMARY,
            background: paperTheme.colors.background,
            card: paperTheme.colors.surface,
          },
        }
      : {
          ...DefaultTheme,
          colors: {
            ...DefaultTheme.colors,
            primary: OPEN_LOCKER_PRIMARY,
            background: paperTheme.colors.background,
            card: paperTheme.colors.surface,
          },
        };

  return (
    <PaperProvider theme={paperTheme}>
      <ThemeProvider value={navigationTheme}>
        <BottomSheetModalProvider>
          <Stack>
            <Stack.Protected guard={!!token}>
              <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
              <Stack.Screen name="account" options={{ title: 'Account' }} />
              <Stack.Screen
                name="terms"
                options={{ title: 'Terms & Conditions', presentation: 'modal' }}
              />
              <Stack.Screen name="modal" options={{ presentation: 'modal' }} />
            </Stack.Protected>

            <Stack.Protected guard={!token}>
              <Stack.Screen name="sign-in" options={{ headerShown: false }} />
              <Stack.Screen name="change-server" options={{ title: 'Change Server' }} />
              <Stack.Screen name="forgot-password" options={{ title: 'Forgot Password' }} />
              <Stack.Screen name="reset-password" options={{ title: 'Reset Password' }} />
            </Stack.Protected>
          </Stack>
        </BottomSheetModalProvider>
      </ThemeProvider>
    </PaperProvider>
  );
}
