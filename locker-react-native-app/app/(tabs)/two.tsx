import React from 'react';
import { StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, Card, Text, useTheme } from 'react-native-paper';

import { getApiBaseUrl } from '@/src/api/baseUrl';
import { useAuth } from '@/src/auth/AuthContext';

export default function AccountScreen() {
  const { userName, signOut } = useAuth();
  const theme = useTheme();

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: theme.colors.background }]} edges={['bottom']}>
      <View style={styles.container}>
        <Card>
          <Card.Title title="Account" />
          <Card.Content style={styles.content}>
            <Text variant="bodyMedium">Signed in as:</Text>
            <Text variant="titleMedium" style={styles.name}>
              {userName ?? 'Unknown user'}
            </Text>

            <Text variant="bodyMedium" style={styles.label}>
              API base URL:
            </Text>
            <Text selectable>{getApiBaseUrl()}</Text>
          </Card.Content>
          <Card.Actions>
            <Button
              mode="contained"
              onPress={() => {
                void signOut();
              }}
            >
              Logout
            </Button>
          </Card.Actions>
        </Card>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  container: { flex: 1, padding: 16, justifyContent: 'flex-start' },
  content: { gap: 6 },
  name: { marginBottom: 8 },
  label: { marginTop: 8, opacity: 0.7 },
});
