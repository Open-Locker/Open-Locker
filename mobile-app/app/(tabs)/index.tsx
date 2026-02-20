import React from 'react';
import { Image, RefreshControl, SectionList, StyleSheet, View } from 'react-native';
import { skipToken } from '@reduxjs/toolkit/query';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ActivityIndicator, Card, Chip, List, Text, useTheme } from 'react-native-paper';

import { useGetCompartmentsQuery } from '@/src/store/generatedApi';
import { useAppSelector } from '@/src/store/hooks';

type CompartmentItem = {
  id: number;
  name: string;
  description: string | null;
  image_url?: string | null;
  borrowed_at?: string | null;
};

type CompartmentEntry = {
  id: string;
  number: number;
  item: CompartmentItem | null;
};

type LockerBankGroup = {
  id: string;
  title: string;
  data: CompartmentEntry[];
};

type AccessibleCompartmentsResponse = {
  status: boolean;
  locker_banks: {
    id: string;
    name: string;
    location_description: string;
    compartments: CompartmentEntry[];
  }[];
};

function mapSections(response: AccessibleCompartmentsResponse | undefined): LockerBankGroup[] {
  if (!response?.locker_banks) return [];
  return response.locker_banks
    .map((bank) => ({
      id: bank.id,
      title: bank.name?.trim() || `Locker bank ${bank.id}`,
      data: Array.isArray(bank.compartments) ? bank.compartments : [],
    }))
    .sort((a, b) => a.title.localeCompare(b.title));
}

export default function CompartmentsScreen() {
  const token = useAppSelector((state) => state.auth.token);
  const theme = useTheme();
  const {
    data,
    error,
    isLoading,
    isFetching,
    refetch: refetchCompartments,
  } = useGetCompartmentsQuery(token ? undefined : skipToken);

  if (isLoading && !data) {
    return (
      <SafeAreaView
        style={[styles.safe, { backgroundColor: theme.colors.background }]}
        edges={['bottom']}
      >
        <View style={styles.center}>
          <ActivityIndicator />
          <Text style={styles.centerText}>Loading compartments…</Text>
        </View>
      </SafeAreaView>
    );
  }

  const sections = mapSections(data as AccessibleCompartmentsResponse | undefined);
  const errorMessage =
    error && 'status' in error ? `Failed to load compartments (${String(error.status)}).` : null;

  return (
    <SafeAreaView
      style={[styles.safe, { backgroundColor: theme.colors.background }]}
      edges={['bottom']}
    >
      {errorMessage ? (
        <Text style={styles.error} accessibilityRole="alert">
          {errorMessage}
        </Text>
      ) : null}

      <SectionList
        sections={sections}
        keyExtractor={(item) => item.id}
        refreshControl={
          <RefreshControl
            refreshing={isFetching && !isLoading}
            onRefresh={() => {
              void refetchCompartments();
            }}
          />
        }
        contentContainerStyle={styles.listContent}
        renderSectionHeader={({ section }) => <List.Subheader>{section.title}</List.Subheader>}
        renderItem={({ item }) => {
          const itemName = item.item?.name?.trim();
          const isEmpty = !item.item;
          const borrowedAt = item.item?.borrowed_at ?? null;
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
                        <Text
                          variant="bodyMedium"
                          style={styles.cardDescriptionMuted}
                          numberOfLines={2}
                        >
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
