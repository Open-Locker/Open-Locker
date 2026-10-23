import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Text, useTheme } from 'react-native-paper';

import { useGetOrganizationsQuery } from '@/src/store/generatedApi';
import { useAppSelector } from '@/src/store/hooks';

import { effectiveOrganizationId } from './effectiveOrganization';
import { useSwitchOrganization } from './useSwitchOrganization';

/**
 * Which organization the locker list shows, as underlined tabs above the
 * locker bank chips: one level up, so it reads as text rather than as another
 * row of pills. Tabs size to their names and scroll, so no name is truncated
 * however many organizations there are.
 *
 * Renders nothing for someone who belongs to one organization, so they never
 * learn the concept exists.
 */
export function OrganizationSwitcher() {
  const { t } = useTranslation();
  const theme = useTheme();
  const activeOrganizationId = useAppSelector((state) => state.organization.activeOrganizationId);
  const switchOrganization = useSwitchOrganization();
  const { data } = useGetOrganizationsQuery({});
  const organizations = data ?? [];

  if (organizations.length < 2) return null;

  const currentId = effectiveOrganizationId(activeOrganizationId, organizations);

  return (
    <View style={[styles.bar, { borderBottomColor: theme.colors.outlineVariant }]}>
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={styles.rail}
        accessibilityRole="tablist"
        accessibilityLabel={t('organization.switch')}
      >
        {organizations.map((organization) => {
          const isCurrent = organization.id === currentId;

          return (
            <Pressable
              key={organization.id}
              onPress={() => {
                if (!isCurrent) switchOrganization(organization.id);
              }}
              accessibilityRole="tab"
              accessibilityState={{ selected: isCurrent }}
              hitSlop={6}
              style={({ pressed }) => [
                styles.tab,
                { borderBottomColor: isCurrent ? theme.colors.primary : 'transparent' },
                pressed && !isCurrent && styles.pressed,
              ]}
            >
              <Text
                variant="titleSmall"
                style={[
                  isCurrent ? styles.currentLabel : styles.label,
                  { color: isCurrent ? theme.colors.primary : theme.colors.onSurfaceVariant },
                ]}
              >
                {organization.name}
              </Text>
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    borderBottomWidth: StyleSheet.hairlineWidth,
    marginBottom: 10,
  },
  rail: {
    gap: 20,
    paddingRight: 8,
  },
  tab: {
    paddingTop: 4,
    paddingBottom: 8,
    borderBottomWidth: 2,
    // Sits on the bar's hairline so the underline replaces it under the tab.
    marginBottom: -StyleSheet.hairlineWidth,
  },
  pressed: {
    opacity: 0.6,
  },
  label: {
    fontFamily: 'Inter_500Medium',
  },
  currentLabel: {
    fontFamily: 'Inter_600SemiBold',
  },
});
