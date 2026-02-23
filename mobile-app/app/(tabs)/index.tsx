import React from 'react';
import { RefreshControl, SectionList, StyleSheet, View } from 'react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { skipToken } from '@reduxjs/toolkit/query';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  ActivityIndicator,
  Button,
  Card,
  HelperText,
  List,
  Modal,
  Portal,
  Text,
  useTheme,
} from 'react-native-paper';

import {
  type GetCompartmentsAccessibleApiResponse,
  useGetCompartmentsAccessibleQuery,
  usePostCompartmentsByCompartmentOpenMutation,
} from '@/src/store/generatedApi';
import { useAppSelector } from '@/src/store/hooks';

type LockerBank = GetCompartmentsAccessibleApiResponse['locker_banks'][number];
type CompartmentEntry = LockerBank['compartments'][number];

type LockerBankGroup = {
  id: string;
  title: string;
  data: CompartmentEntry[];
};

function mapSections(
  response: GetCompartmentsAccessibleApiResponse | undefined,
): LockerBankGroup[] {
  if (!response?.locker_banks) return [];
  return response.locker_banks
    .map((bank) => ({
      id: bank.id,
      title: bank.name?.trim() || `Locker bank ${bank.id}`,
      data: Array.isArray(bank.compartments) ? bank.compartments : [],
    }))
    .sort((a, b) => a.title.localeCompare(b.title));
}

function getErrorMessage(error: unknown): string {
  const apiError = error as FetchBaseQueryError | undefined;
  if (apiError && typeof apiError === 'object' && 'status' in apiError) {
    return `Request failed (${String(apiError.status)}).`;
  }
  if (error instanceof Error) return error.message;
  return 'Something went wrong.';
}

export default function CompartmentsScreen() {
  const token = useAppSelector((state) => state.auth.token);
  const theme = useTheme();
  const [requestOpen, requestOpenState] = usePostCompartmentsByCompartmentOpenMutation();
  const {
    data,
    error,
    isLoading,
    isFetching,
    refetch: refetchCompartments,
  } = useGetCompartmentsAccessibleQuery(token ? undefined : skipToken);
  const [selectedCompartment, setSelectedCompartment] = React.useState<CompartmentEntry | null>(
    null,
  );
  const [modalError, setModalError] = React.useState<string | null>(null);
  const [modalInfo, setModalInfo] = React.useState<string | null>(null);

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

  const sections = mapSections(data);
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
          const description = item.item?.description?.trim() ?? '';

          return (
            <View style={styles.cardWrap}>
              <Card
                mode="elevated"
                style={styles.card}
                onPress={
                  item.item
                    ? () => {
                        setModalError(null);
                        setModalInfo(null);
                        setSelectedCompartment(item);
                      }
                    : undefined
                }
              >
                <Card.Content style={styles.cardContent}>
                  <View style={styles.cardRow}>
                    <View style={styles.thumbPlaceholder} />

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
      <Portal>
        <Modal
          visible={!!selectedCompartment}
          onDismiss={() => setSelectedCompartment(null)}
          contentContainerStyle={[styles.modalContent, { backgroundColor: theme.colors.surface }]}
        >
          <Text variant="titleMedium" style={styles.modalTitle}>
            {selectedCompartment?.item?.name ?? `Compartment ${selectedCompartment?.number ?? ''}`}
          </Text>
          <Text variant="bodyMedium" style={styles.modalSubtitle}>
            Compartment {selectedCompartment?.number}
          </Text>
          <Text variant="bodySmall" style={styles.modalHint}>
            Send open command for this compartment.
          </Text>
          <HelperText type="error" visible={!!modalError}>
            {modalError}
          </HelperText>
          <HelperText type="info" visible={!!modalInfo}>
            {modalInfo}
          </HelperText>
          <Button
            mode="contained"
            onPress={() => {
              if (!selectedCompartment) return;
              void (async () => {
                setModalError(null);
                setModalInfo(null);
                try {
                  await requestOpen({ compartment: selectedCompartment.id }).unwrap();
                  setModalInfo('Open request sent.');
                  setSelectedCompartment(null);
                } catch (e) {
                  setModalError(getErrorMessage(e));
                }
              })();
            }}
            loading={requestOpenState.isLoading}
            disabled={!selectedCompartment || requestOpenState.isLoading}
          >
            Open compartment
          </Button>
          <Button mode="text" onPress={() => setSelectedCompartment(null)}>
            Close
          </Button>
        </Modal>
      </Portal>
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
  cardTitle: { fontWeight: '700' },
  cardSubtitle: { opacity: 0.7, marginBottom: 4 },
  cardDescription: { lineHeight: 20 },
  cardDescriptionMuted: { opacity: 0.7, lineHeight: 20 },
  modalContent: {
    margin: 16,
    borderRadius: 12,
    padding: 16,
    gap: 8,
  },
  modalTitle: { fontWeight: '700' },
  modalSubtitle: { opacity: 0.8 },
  modalHint: { opacity: 0.7, marginBottom: 6 },
});
