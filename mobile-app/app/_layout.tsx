import {
  Inter_400Regular,
  Inter_500Medium,
  Inter_600SemiBold,
  Inter_700Bold,
} from '@expo-google-fonts/inter';
import { BottomSheetModalProvider } from '@gorhom/bottom-sheet';
import { ThemeProvider } from '@react-navigation/native';
import { useFonts } from 'expo-font';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import 'react-native-reanimated';
import { Provider } from 'react-redux';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { PaperProvider } from 'react-native-paper';

import { useColorScheme } from '@/components/useColorScheme';
import { hydrateApiBaseUrl } from '@/src/api/baseUrl';
import '@/src/i18n';
import { loadPersistedAuth } from '@/src/store/authStorage';
import { restoreAuth } from '@/src/store/authSlice';
import { useAppDispatch, useAppSelector } from '@/src/store/hooks';
import { store } from '@/src/store/store';
import { createNavigationTheme, createPaperTheme } from '@/src/theme/themeFactory';

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
  const { t } = useTranslation();
  const colorScheme = useColorScheme();
  const token = useAppSelector((state) => state.auth.token);
  const isLoading = useAppSelector((state) => state.auth.isLoading);

  if (isLoading) {
    return null;
  }

  const paperTheme = createPaperTheme(colorScheme);
  const navigationTheme = createNavigationTheme(colorScheme, paperTheme);

  return (
    <PaperProvider theme={paperTheme}>
      <ThemeProvider value={navigationTheme}>
        <BottomSheetModalProvider>
          <Stack>
            <Stack.Protected guard={!!token}>
              <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
              <Stack.Screen name="account" options={{ title: t('navigation.account') }} />
              <Stack.Screen
                name="terms"
                options={{ title: t('navigation.terms'), presentation: 'modal' }}
              />
              <Stack.Screen name="modal" options={{ presentation: 'modal' }} />
            </Stack.Protected>

            <Stack.Protected guard={!token}>
              <Stack.Screen name="sign-in" options={{ headerShown: false }} />
              <Stack.Screen
                name="change-server"
                options={{ title: t('navigation.changeServer') }}
              />
              <Stack.Screen
                name="forgot-password"
                options={{ title: t('navigation.forgotPassword') }}
              />
              <Stack.Screen
                name="reset-password"
                options={{ title: t('navigation.resetPassword') }}
              />
            </Stack.Protected>
          </Stack>
        </BottomSheetModalProvider>
      </ThemeProvider>
    </PaperProvider>
  );
}
