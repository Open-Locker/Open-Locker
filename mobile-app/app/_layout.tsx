import FontAwesome from '@expo/vector-icons/FontAwesome';
import { DarkTheme, DefaultTheme, ThemeProvider } from '@react-navigation/native';
import { useFonts } from 'expo-font';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { useEffect } from 'react';
import 'react-native-reanimated';
import { Provider } from 'react-redux';
import { MD3DarkTheme, MD3LightTheme, PaperProvider } from 'react-native-paper';

import { useColorScheme } from '@/components/useColorScheme';
import { hydrateApiBaseUrl } from '@/src/api/baseUrl';
import { OPEN_LOCKER_PRIMARY } from '@/src/config/theme';
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
    <Provider store={store}>
      <RootLayoutBootstrap />
    </Provider>
  );
}

function RootLayoutBootstrap() {
  const dispatch = useAppDispatch();
  const [loaded, error] = useFonts({
    SpaceMono: require('../assets/fonts/SpaceMono-Regular.ttf'),
    ...FontAwesome.font,
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

  const lightBackground = '#fbfdf5'; // subtle green tint
  const lightSurface = '#ffffff';

  const paperTheme =
    colorScheme === 'dark'
      ? {
          ...MD3DarkTheme,
          colors: {
            ...MD3DarkTheme.colors,
            primary: OPEN_LOCKER_PRIMARY,
            secondary: '#8aa400',
            tertiary: '#afca0b',
            background: '#0f1208',
            surface: '#12150a',
            surfaceVariant: '#1f2612',
            outline: '#6f7a3b',
            outlineVariant: '#3a4321',
          },
          elevation: {
            level0: '#12150a',
            level1: '#161a0c',
            level2: '#1a1f0f',
            level3: '#1f2612',
            level4: '#242d15',
            level5: '#283418',
          },
        }
      : {
          ...MD3LightTheme,
          colors: {
            ...MD3LightTheme.colors,
            primary: OPEN_LOCKER_PRIMARY,
            secondary: '#6f8b00',
            tertiary: '#afca0b',
            background: lightBackground,
            surface: lightSurface,
            // Used by Paper inputs/cards as background tint (replaces default purple hue)
            surfaceVariant: '#f1f5e0',
            outline: '#7a8d2a',
            outlineVariant: '#d5ddb2',
          },
          // Remove default MD3 purple-tinted elevation surfaces.
          elevation: {
            level0: lightSurface,
            level1: lightSurface,
            level2: lightSurface,
            level3: lightSurface,
            level4: lightSurface,
            level5: lightSurface,
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
        <Stack>
          <Stack.Protected guard={!!token}>
            <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
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
      </ThemeProvider>
    </PaperProvider>
  );
}
