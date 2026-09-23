import React from 'react';
import { Stack, router } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { FlatList, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ActivityIndicator, Card, HelperText, Text, useTheme } from 'react-native-paper';
import { Building2, Check } from 'lucide-react-native';

import { useGetOrganizationsQuery } from '@/src/store/generatedApi';
import { useAppDispatch, useAppSelector } from '@/src/store/hooks';
import { setActiveOrganization } from '@/src/store/organizationSlice';
import { baseApi } from '@/src/store/baseApi';
import { getApiErrorMessage } from '@/src/store/apiErrorMessage';
import { OPEN_LOCKER_DESIGN_TOKENS } from '@/src/theme/tokens';

type Organization = { id: string; name: string; slug: string };

/**
 * Reached from the account screen, never on the way in.
 *
 * Signing in does not ask which operator you meant — the server starts you in
 * one you belong to. Someone who works for two comes here to move between them,
 * which is a choice they make when they want it rather than a gate in front of
 * the app.
 */
export default function SelectOrganizationScreen() {
  const { t } = useTranslation();
  const theme = useTheme();
  const dispatch = useAppDispatch();
  const activeOrganizationId = useAppSelector((state) => state.organization.activeOrganizationId);

  const { data, isLoading, error } = useGetOrganizationsQuery({});
  const organizations = (data ?? []) as Organization[];

  const choose = (organizationId: string) => {
    if (organizationId === activeOrganizationId) {
      router.back();
      return;
    }

    dispatch(setActiveOrganization(organizationId));
    // Everything already fetched belongs to the organization being left.
    dispatch(baseApi.util.resetApiState());
    router.back();
  };

  return (
    <SafeAreaView style={[styles.screen, { backgroundColor: theme.colors.background }]}>
      <Stack.Screen options={{ title: t('organization.selectTitle') }} />

      {isLoading ? (
        <View style={styles.centered}>
          <ActivityIndicator />
        </View>
      ) : error ? (
        <View style={styles.centered}>
          <HelperText type="error" visible>
            {getApiErrorMessage(error, t, { fallbackKey: 'organization.loadFailed' })}
          </HelperText>
        </View>
      ) : (
        <FlatList
          data={organizations}
          keyExtractor={(organization) => organization.id}
          contentContainerStyle={styles.list}
          ListHeaderComponent={
            <Text
              variant="bodyMedium"
              style={[styles.intro, { color: theme.colors.onSurfaceVariant }]}
            >
              {t('organization.selectIntro')}
            </Text>
          }
          renderItem={({ item }) => {
            const isActive = item.id === activeOrganizationId;
            const textColor = isActive ? theme.colors.onPrimaryContainer : theme.colors.onSurface;

            return (
              <Pressable
                onPress={() => choose(item.id)}
                style={({ pressed }) => [styles.pressable, pressed && styles.pressed]}
              >
                <Card
                  mode={isActive ? 'contained' : 'outlined'}
                  style={[
                    styles.card,
                    isActive && { backgroundColor: theme.colors.primaryContainer },
                  ]}
                >
                  <Card.Content style={styles.cardContent}>
                    <Building2 size={22} color={textColor} />
                    <View style={styles.labels}>
                      <Text variant="titleMedium" style={{ color: textColor }}>
                        {item.name}
                      </Text>
                      <Text
                        variant="bodySmall"
                        style={{
                          color: isActive
                            ? theme.colors.onPrimaryContainer
                            : theme.colors.onSurfaceVariant,
                        }}
                      >
                        {isActive ? t('organization.current') : item.slug}
                      </Text>
                    </View>
                    {isActive ? <Check size={20} color={textColor} /> : null}
                  </Card.Content>
                </Card>
              </Pressable>
            );
          }}
        />
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  list: {
    padding: OPEN_LOCKER_DESIGN_TOKENS.spacing.lg,
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.md,
  },
  intro: { marginBottom: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm },
  pressable: { borderRadius: OPEN_LOCKER_DESIGN_TOKENS.radius.lg },
  pressed: { opacity: 0.85 },
  card: { borderRadius: OPEN_LOCKER_DESIGN_TOKENS.radius.lg },
  cardContent: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: OPEN_LOCKER_DESIGN_TOKENS.spacing.md,
    paddingVertical: OPEN_LOCKER_DESIGN_TOKENS.spacing.sm,
  },
  labels: { flex: 1, gap: 2 },
});
