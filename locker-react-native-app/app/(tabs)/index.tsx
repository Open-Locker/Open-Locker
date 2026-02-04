import React from 'react';
import { Image, RefreshControl, SectionList, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ActivityIndicator, Card, Chip, List, Text, useTheme } from 'react-native-paper';

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
  const theme = useTheme();
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
      <SafeAreaView style={[styles.safe, { backgroundColor: theme.colors.background }]} edges={['bottom']}>
        <View style={styles.center}>
          <ActivityIndicator />
          <Text style={styles.centerText}>Loading compartments…</Text>
        </View>
      </SafeAreaView>
    );
  }

  const sections = groupByLockerBank(data ?? []);

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: theme.colors.background }]} edges={['bottom']}>
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
          const statusLabel = isEmpty ? 'Empty' : borrowedAt ? 'Borrowed' : 'Available';
          const imageUrl = item.item?.image_url ?? null;
          const description = item.item?.description?.trim() ?? '';

          return (
            <View style={styles.cardWrap}>
              <Card mode="elevated" style={styles.card}>
                <Card.Content style={styles.cardContent}>
                  <View style={styles.cardRow}>
                    {imageUrl ? (
                      <Image source={{ uri: imageUrl }} style={styles.thumb} resizeMode="cover" />
                    ) : (
                      <View style={styles.thumbPlaceholder} />
                    )}

                    <View style={styles.cardMain}>
                      <Text variant="titleMedium" numberOfLines={1} style={styles.cardTitle}>
                        {itemName && itemName.length > 0 ? itemName : `Compartment ${item.number}`}
                      </Text>

                      <Text variant="bodySmall" numberOfLines={1} style={styles.cardSubtitle}>
                        Compartment {item.number}
                      </Text>

                      {description.length > 0 ? (
                        <Text variant="bodyMedium" style={styles.cardDescription} numberOfLines={2}>
                          {description}
                        </Text>
                      ) : (
                        <Text variant="bodyMedium" style={styles.cardDescriptionMuted} numberOfLines={2}>
                          {isEmpty ? 'No item in this compartment.' : 'No description.'}
                        </Text>
                      )}
                    </View>

                    <View style={styles.cardSide}>
                      <Chip
                        compact
                        selected={!isEmpty}
                        icon={isEmpty ? 'inbox-outline' : 'package-variant'}
                        style={styles.statusChip}
                      >
                        {statusLabel}
                      </Chip>
                    </View>
                  </View>
                </Card.Content>
              </Card>
            </View>
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
  cardWrap: { paddingHorizontal: 16, paddingBottom: 12 },
  card: { width: '100%' },
  cardContent: { paddingVertical: 12 },
  cardRow: { flexDirection: 'row', gap: 12 },
  thumb: { width: 80, height: 80, borderRadius: 12, backgroundColor: '#e9eed4' },
  thumbPlaceholder: { width: 80, height: 80, borderRadius: 12, backgroundColor: '#e9eed4' },
  cardMain: { flex: 1, minWidth: 0, gap: 2 },
  cardSide: { alignItems: 'flex-end', justifyContent: 'flex-start' },
  statusChip: { alignSelf: 'flex-end' },
  cardTitle: { fontWeight: '700' },
  cardSubtitle: { opacity: 0.7, marginBottom: 4 },
  cardDescription: { lineHeight: 20 },
  cardDescriptionMuted: { opacity: 0.7, lineHeight: 20 },
});
