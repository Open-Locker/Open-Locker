import React from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, HelperText, Text, TextInput, useTheme } from 'react-native-paper';

import { ApiError } from '@/src/api/http';
import { useAuth } from '@/src/auth/AuthContext';
import { OPEN_LOCKER_PRIMARY } from '@/src/config/theme';

function getErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 422) {
      return 'Invalid email or password.';
    }
    return `Request failed (${error.status}).`;
  }
  if (error instanceof Error) return error.message;
  return 'Something went wrong.';
}

export default function SignInScreen() {
  const { signIn } = useAuth();
  const theme = useTheme();

  const [email, setEmail] = React.useState('');
  const [password, setPassword] = React.useState('');
  const [isSubmitting, setIsSubmitting] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const canSubmit = email.trim().length > 0 && password.length > 0 && !isSubmitting;

  const onSubmit = React.useCallback(async () => {
    setError(null);
    setIsSubmitting(true);
    try {
      await signIn(email.trim(), password);
    } catch (e) {
      setError(getErrorMessage(e));
      setIsSubmitting(false);
    }
  }, [email, password, signIn]);

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: theme.colors.background }]} edges={['top', 'bottom']}>
      <KeyboardAvoidingView
        style={styles.container}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <Text variant="headlineMedium" style={styles.title}>
          Open Locker
        </Text>
        <Text variant="bodyMedium" style={styles.subtitle}>
          Sign in to view compartments and items.
        </Text>

        <TextInput
          label="Email"
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="email-address"
          textContentType="username"
          style={styles.input}
        />

        <TextInput
          label="Password"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          textContentType="password"
          style={styles.input}
        />

        <HelperText type="error" visible={!!error}>
          {error}
        </HelperText>

        <Button
          mode="contained"
          onPress={onSubmit}
          disabled={!canSubmit}
          loading={isSubmitting}
          buttonColor={OPEN_LOCKER_PRIMARY}
          style={styles.button}
        >
          Sign in
        </Button>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: {
    flex: 1,
    paddingHorizontal: 16,
    paddingTop: 28,
    gap: 8,
  },
  title: { fontWeight: '700' },
  subtitle: { opacity: 0.8, marginBottom: 12 },
  input: { marginTop: 8 },
  button: { marginTop: 12 },
});

