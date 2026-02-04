import React from 'react';
import { RefreshControl, SectionList, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ActivityIndicator, List, Text } from 'react-native-paper';

import { fetchCompartments } from '@/src/api/compartmentsApi';
import { useAuth } from '@/src/auth/AuthContext';
import type { CompartmentDto } from '@/src/types/api';

type Section = {
  title: string;
  data: CompartmentDto[];
};

function groupByLockerBank(compartments: CompartmentDto[]): Section[] {
  const map = new Map<string, { title: string; data: CompartmentDto[] }>();

  for (const c of compartments) {
    const bankName = c.locker_bank?.name?.trim();
    const key = c.locker_bank_id;
    const title = bankName && bankName.length > 0 ? bankName : `Locker bank ${c.locker_bank_id}`;

    const existing = map.get(key);
    if (existing) {
      existing.data.push(c);
    } else {
      map.set(key, { title, data: [c] });
    }
  }

  return Array.from(map.values()).sort((a, b) => a.title.localeCompare(b.title));
}

export default function CompartmentsScreen() {
  const { token } = useAuth();
  const [data, setData] = React.useState<CompartmentDto[] | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [isLoading, setIsLoading] = React.useState(true);
  const [isRefreshing, setIsRefreshing] = React.useState(false);

  const load = React.useCallback(async () => {
    if (!token) return;
    setError(null);
    const compartments = await fetchCompartments(token);
    setData(compartments);
  }, [token]);

  React.useEffect(() => {
    let cancelled = false;
    (async () => {
      if (!token) return;
      try {
        setIsLoading(true);
        await load();
      } catch (e) {
        if (!cancelled) setError(e instanceof Error ? e.message : 'Failed to load compartments.');
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [load, token]);

  const onRefresh = React.useCallback(async () => {
    if (!token) return;
    setIsRefreshing(true);
    try {
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to refresh.');
    } finally {
      setIsRefreshing(false);
    }
  }, [load, token]);

  if (isLoading && !data) {
    return (
      <SafeAreaView style={styles.safe} edges={['bottom']}>
        <View style={styles.center}>
          <ActivityIndicator />
          <Text style={styles.centerText}>Loading compartments…</Text>
        </View>
      </SafeAreaView>
    );
  }

  const sections = groupByLockerBank(data ?? []);

  return (
    <SafeAreaView style={styles.safe} edges={['bottom']}>
      {error ? (
        <Text style={styles.error} accessibilityRole="alert">
          {error}
        </Text>
      ) : null}

      <SectionList
        sections={sections}
        keyExtractor={(item) => item.id}
        refreshControl={<RefreshControl refreshing={isRefreshing} onRefresh={onRefresh} />}
        contentContainerStyle={styles.listContent}
        renderSectionHeader={({ section }) => <List.Subheader>{section.title}</List.Subheader>}
        renderItem={({ item }) => {
          const itemName = item.item?.name?.trim();
          const isEmpty = !item.item;
          const borrowedAt = item.item?.borrowed_at;
          const subtitle = isEmpty
            ? 'Empty'
            : borrowedAt
              ? 'Borrowed'
              : 'Available';

          return (
            <List.Item
              title={`Compartment ${item.number}`}
              description={`${subtitle}${itemName ? ` · ${itemName}` : ''}`}
              left={(props) => <List.Icon {...props} icon={isEmpty ? 'inbox-outline' : 'package-variant'} />}
            />
          );
        }}
        ListEmptyComponent={
          <View style={styles.center}>
            <Text>No compartments found.</Text>
          </View>
        }
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  listContent: { paddingBottom: 24 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 12 },
  centerText: { opacity: 0.7 },
  error: { color: '#b00020', paddingHorizontal: 16, paddingTop: 12 },
});
