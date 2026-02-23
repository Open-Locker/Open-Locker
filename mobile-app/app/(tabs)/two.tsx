import React from 'react';
import { ScrollView, StyleSheet } from 'react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, Card, HelperText, Text, TextInput, useTheme } from 'react-native-paper';

import {
  getApiBaseUrl,
  normalizeApiBaseUrl,
  resetApiBaseUrlToDefault,
  setApiBaseUrl,
} from '@/src/api/baseUrl';
import { baseApi } from '@/src/store/baseApi';
import { clearPersistedAuth } from '@/src/store/authStorage';
import { clearCredentials } from '@/src/store/authSlice';
import {
  useGetUserQuery,
  usePostLogoutMutation,
  usePutPasswordMutation,
  usePutProfileMutation,
} from '@/src/store/generatedApi';
import { useAppDispatch, useAppSelector } from '@/src/store/hooks';

function getErrorMessage(error: unknown): string {
  const apiError = error as FetchBaseQueryError | undefined;
  if (apiError && typeof apiError === 'object' && 'status' in apiError) {
    return `Request failed (${String(apiError.status)}).`;
  }
  if (error instanceof Error) {
    return error.message;
  }
  return 'Something went wrong.';
}

export default function AccountScreen() {
  const dispatch = useAppDispatch();
  const userName = useAppSelector((state) => state.auth.userName);
  const theme = useTheme();
  const { data: user, refetch } = useGetUserQuery();
  const [updateProfile, updateProfileState] = usePutProfileMutation();
  const [changePassword, changePasswordState] = usePutPasswordMutation();
  const [logoutCurrentSession] = usePostLogoutMutation();

  const [name, setName] = React.useState('');
  const [email, setEmail] = React.useState('');
  const [currentPassword, setCurrentPassword] = React.useState('');
  const [newPassword, setNewPassword] = React.useState('');
  const [newPasswordConfirmation, setNewPasswordConfirmation] = React.useState('');
  const [customApiBaseUrl, setCustomApiBaseUrl] = React.useState(getApiBaseUrl());
  const [profileMessage, setProfileMessage] = React.useState<string | null>(null);
  const [passwordMessage, setPasswordMessage] = React.useState<string | null>(null);
  const [backendMessage, setBackendMessage] = React.useState<string | null>(null);
  const [backendError, setBackendError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (user) {
      setName(user.name);
      setEmail(user.email);
    }
  }, [user]);

  const clearSession = React.useCallback(async () => {
    await clearPersistedAuth();
    dispatch(baseApi.util.resetApiState());
    dispatch(clearCredentials());
  }, [dispatch]);

  const onLogout = React.useCallback(async () => {
    try {
      await logoutCurrentSession().unwrap();
    } catch {
      // ignore network/logout race and clear local session anyway
    } finally {
      await clearSession();
    }
  }, [clearSession, logoutCurrentSession]);

  const onSaveProfile = React.useCallback(async () => {
    setProfileMessage(null);
    const normalizedNewEmail = email.trim().toLowerCase();
    const previousEmail = user?.email?.trim().toLowerCase() ?? null;
    const emailChanged = previousEmail !== null && previousEmail !== normalizedNewEmail;
    try {
      await updateProfile({
        updateProfileRequest: { name: name.trim(), email: email.trim() },
      }).unwrap();
      await refetch();
      setProfileMessage(
        emailChanged
          ? 'Profile updated. Please verify your new email address.'
          : 'Profile updated.',
      );
    } catch (error) {
      setProfileMessage(getErrorMessage(error));
    }
  }, [email, name, refetch, updateProfile, user?.email]);

  const onChangePassword = React.useCallback(async () => {
    setPasswordMessage(null);
    try {
      await changePassword({
        changePasswordRequest: {
          current_password: currentPassword,
          password: newPassword,
          password_confirmation: newPasswordConfirmation,
        },
      }).unwrap();
      setCurrentPassword('');
      setNewPassword('');
      setNewPasswordConfirmation('');
      setPasswordMessage('Password updated.');
    } catch (error) {
      setPasswordMessage(getErrorMessage(error));
    }
  }, [changePassword, currentPassword, newPassword, newPasswordConfirmation]);

  const onSaveBackendUrl = React.useCallback(async () => {
    setBackendError(null);
    setBackendMessage(null);

    const normalized = normalizeApiBaseUrl(customApiBaseUrl);
    if (!normalized) {
      setBackendError('Please enter a valid backend URL.');
      return;
    }

    try {
      const identifyResponse = await fetch(`${normalized}/identify`, {
        method: 'GET',
        headers: { Accept: 'application/json' },
      });
      if (!identifyResponse.ok) {
        setBackendError(`Backend check failed (${identifyResponse.status}).`);
        return;
      }

      await setApiBaseUrl(normalized);
      setBackendMessage('Backend updated. Please sign in again.');
      await clearSession();
    } catch (error) {
      setBackendError(getErrorMessage(error));
    }
  }, [clearSession, customApiBaseUrl]);

  const onResetBackendUrl = React.useCallback(async () => {
    setBackendError(null);
    setBackendMessage(null);
    await resetApiBaseUrlToDefault();
    setCustomApiBaseUrl(getApiBaseUrl());
    setBackendMessage('Backend reset to default. Please sign in again.');
    await clearSession();
  }, [clearSession]);

  return (
    <SafeAreaView
      style={[styles.safe, { backgroundColor: theme.colors.background }]}
      edges={['bottom']}
    >
      <ScrollView contentContainerStyle={styles.container}>
        <Card>
          <Card.Title title="Account" />
          <Card.Content style={styles.content}>
            <Text variant="bodyMedium">Signed in as:</Text>
            <Text variant="titleMedium" style={styles.name}>
              {userName ?? 'Unknown user'}
            </Text>

            <TextInput label="Name" value={name} onChangeText={setName} />
            <TextInput
              label="Email"
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              keyboardType="email-address"
            />
            <Button
              mode="contained"
              onPress={() => void onSaveProfile()}
              loading={updateProfileState.isLoading}
            >
              Save profile
            </Button>
            <HelperText type="info" visible={!!profileMessage}>
              {profileMessage}
            </HelperText>

            <Text variant="titleSmall" style={styles.sectionTitle}>
              Change password
            </Text>
            <TextInput
              label="Current password"
              value={currentPassword}
              onChangeText={setCurrentPassword}
              secureTextEntry
            />
            <TextInput
              label="New password"
              value={newPassword}
              onChangeText={setNewPassword}
              secureTextEntry
            />
            <TextInput
              label="Confirm new password"
              value={newPasswordConfirmation}
              onChangeText={setNewPasswordConfirmation}
              secureTextEntry
            />
            <Button
              mode="outlined"
              onPress={() => void onChangePassword()}
              loading={changePasswordState.isLoading}
            >
              Update password
            </Button>
            <HelperText type="info" visible={!!passwordMessage}>
              {passwordMessage}
            </HelperText>

            <Text variant="bodyMedium" style={styles.label}>
              API base URL:
            </Text>
            <TextInput
              value={customApiBaseUrl}
              onChangeText={setCustomApiBaseUrl}
              autoCapitalize="none"
              autoCorrect={false}
            />
            <Button mode="outlined" onPress={() => void onSaveBackendUrl()}>
              Save backend URL
            </Button>
            <Button mode="text" onPress={() => void onResetBackendUrl()}>
              Reset backend URL to default
            </Button>
            <HelperText type="error" visible={!!backendError}>
              {backendError}
            </HelperText>
            <HelperText type="info" visible={!!backendMessage}>
              {backendMessage}
            </HelperText>
          </Card.Content>
          <Card.Actions>
            <Button mode="contained" onPress={() => void onLogout()}>
              Logout
            </Button>
          </Card.Actions>
        </Card>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: { padding: 16, justifyContent: 'flex-start' },
  content: { gap: 6 },
  name: { marginBottom: 8 },
  label: { marginTop: 8, opacity: 0.7 },
  sectionTitle: { marginTop: 12 },
});
