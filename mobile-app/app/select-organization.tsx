import React from 'react';
import { Stack, useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { FlatList, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ActivityIndicator, HelperText, List, Surface, Text } from 'react-native-paper';

import { useGetOrganizationsQuery } from '@/src/store/generatedApi';
import { useAppDispatch, useAppSelector } from '@/src/store/hooks';
import { setActiveOrganization } from '@/src/store/organizationSlice';
import { baseApi } from '@/src/store/baseApi';
import { getApiErrorMessage } from '@/src/store/apiErrorMessage';
import { OPEN_LOCKER_DESIGN_TOKENS } from '@/src/theme/tokens';

type Organization = { id: string; name: string; slug: string };

/**
 * Shown only to someone who belongs to more than one operator.
 *
 * A single-membership user never reaches this screen: the API resolves their one
 * organization on its own, so the concept stays invisible for everyone on a
 * single-organization installation.
 */
export default function SelectOrganizationScreen() {
  const { t } = useTranslation();
  const router = useRouter();
  const dispatch = useAppDispatch();
  const activeOrganizationId = useAppSelector((state) => state.organization.activeOrganizationId);

  const { data, isLoading, error } = useGetOrganizationsQuery({});
  const organizations = (data ?? []) as Organization[];

  const choose = (organizationId: string) => {
    dispatch(setActiveOrganization(organizationId));
    // Everything fetched so far belongs to the organization being left.
    dispatch(baseApi.util.resetApiState());
    router.back();
  };

  return (
    <SafeAreaView style={styles.screen}>
      <Stack.Screen options={{ title: t('organization.selectTitle') }} />

      {isLoading ? (
        <View style={styles.centered}>
          <ActivityIndicator />
        </View>
      ) : error ? (
        <HelperText type="error" visible>
          {getApiErrorMessage(error, t, { fallbackKey: 'organization.loadFailed' })}
        </HelperText>
      ) : (
        <Surface style={styles.surface}>
          <Text variant="bodyMedium" style={styles.intro}>
            {t('organization.selectIntro')}
          </Text>

          <FlatList
            data={organizations}
            keyExtractor={(organization) => organization.id}
            renderItem={({ item }) => (
              <List.Item
                title={item.name}
                onPress={() => choose(item.id)}
                right={(props) =>
                  item.id === activeOrganizationId ? <List.Icon {...props} icon="check" /> : null
                }
              />
            )}
          />
        </Surface>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  surface: { flex: 1, margin: OPEN_LOCKER_DESIGN_TOKENS.spacing.md, borderRadius: 12 },
  intro: { padding: OPEN_LOCKER_DESIGN_TOKENS.spacing.md },
});
