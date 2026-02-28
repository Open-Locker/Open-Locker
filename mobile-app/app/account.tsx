import React from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { SafeAreaView } from 'react-native-safe-area-context';
import { HelperText, Text, useTheme } from 'react-native-paper';

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
import { OPEN_LOCKER_DESIGN_TOKENS } from '@/src/theme/tokens';
import { AppButton, AppTextInput } from '@/src/ui';

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
  const [profileMessage, setProfileMessage] = React.useState<string | null>(null);
  const [passwordMessage, setPasswordMessage] = React.useState<string | null>(null);

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

  return (
    <SafeAreaView
      style={[styles.safe, { backgroundColor: theme.colors.background }]}
      edges={['bottom']}
    >
      <ScrollView contentContainerStyle={styles.container}>
        <Text variant="headlineSmall" style={styles.pageTitle}>
          Account
        </Text>
        <Text variant="bodyMedium" style={styles.pageSubtitle}>
          Signed in as
        </Text>
        <Text variant="titleMedium" style={styles.name}>
          {userName ?? 'Unknown user'}
        </Text>

        <View
          style={[
            styles.sectionCard,
            { backgroundColor: theme.colors.surface, borderColor: theme.colors.outlineVariant },
          ]}
        >
          <Text variant="titleSmall" style={styles.sectionTitle}>
            Profile
          </Text>
          <AppTextInput label="Name" value={name} onChangeText={setName} />
          <AppTextInput
            label="Email"
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            keyboardType="email-address"
          />
          <AppButton
            mode="contained"
            onPress={() => void onSaveProfile()}
            loading={updateProfileState.isLoading}
          >
            Save profile
          </AppButton>
          <HelperText type="info" visible={!!profileMessage}>
            {profileMessage}
          </HelperText>
        </View>

        <View
          style={[
            styles.sectionCard,
            { backgroundColor: theme.colors.surface, borderColor: theme.colors.outlineVariant },
          ]}
        >
          <Text variant="titleSmall" style={styles.sectionTitle}>
            Change password
          </Text>
          <AppTextInput
            label="Current password"
            value={currentPassword}
            onChangeText={setCurrentPassword}
            secureTextEntry
          />
          <AppTextInput
            label="New password"
            value={newPassword}
            onChangeText={setNewPassword}
            secureTextEntry
          />
          <AppTextInput
            label="Confirm new password"
            value={newPasswordConfirmation}
            onChangeText={setNewPasswordConfirmation}
            secureTextEntry
          />
          <AppButton
            mode="outlined"
            onPress={() => void onChangePassword()}
            loading={changePasswordState.isLoading}
          >
            Update password
          </AppButton>
          <HelperText type="info" visible={!!passwordMessage}>
            {passwordMessage}
          </HelperText>
        </View>

        <AppButton mode="contained" onPress={() => void onLogout()} style={styles.logoutButton}>
          Logout
        </AppButton>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: {
    padding: OPEN_LOCKER_DESIGN_TOKENS.spacing.lg,
    justifyContent: 'flex-start',
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
  },
  pageTitle: { fontFamily: 'Inter_700Bold' },
  pageSubtitle: { opacity: 0.72 },
  name: {
    marginBottom: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
    fontFamily: 'Inter_600SemiBold',
  },
  sectionCard: {
    borderWidth: 1,
    borderRadius: OPEN_LOCKER_DESIGN_TOKENS.radius.md,
    padding: OPEN_LOCKER_DESIGN_TOKENS.spacing.md,
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
  },
  sectionTitle: { fontFamily: 'Inter_600SemiBold' },
  logoutButton: {
    marginTop: OPEN_LOCKER_DESIGN_TOKENS.spacing.xs,
  },
});
