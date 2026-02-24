import React from 'react';
import { Pressable, RefreshControl, SectionList, StyleSheet, View } from 'react-native';
import { BottomSheetBackdrop, BottomSheetModal, BottomSheetView } from '@gorhom/bottom-sheet';
import { CircleHelp, Lock, LockOpen, WifiOff } from 'lucide-react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { skipToken } from '@reduxjs/toolkit/query';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import {
  ActivityIndicator,
  Button,
  Card,
  Chip,
  HelperText,
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
type CompartmentVisualStatus = 'open' | 'closed' | 'unknown';
type LockerVisualStatus = 'online' | 'offline';

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

function getFakeCompartmentStatus(compartment: CompartmentEntry): CompartmentVisualStatus {
  if (compartment.number % 5 === 0) return 'unknown';
  return compartment.number % 2 === 0 ? 'closed' : 'open';
}

function getFakeLockerStatus(lockerBankId: string): LockerVisualStatus {
  let checksum = 0;
  for (const character of lockerBankId) {
    checksum += character.charCodeAt(0);
  }
  return checksum % 3 === 0 ? 'offline' : 'online';
}

export default function CompartmentsScreen() {
  const token = useAppSelector((state) => state.auth.token);
  const theme = useTheme();
  const insets = useSafeAreaInsets();
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
  const [selectedLockerBankId, setSelectedLockerBankId] = React.useState<string>('all');
  const [modalError, setModalError] = React.useState<string | null>(null);
  const [modalInfo, setModalInfo] = React.useState<string | null>(null);
  const compartmentSheetRef = React.useRef<BottomSheetModal>(null);
  const [sheetContentHeight, setSheetContentHeight] = React.useState(320);
  const sheetSnapPoints = React.useMemo(
    () => [Math.max(220, sheetContentHeight)],
    [sheetContentHeight],
  );
  const sheetBackdrop = React.useCallback(
    (props: React.ComponentProps<typeof BottomSheetBackdrop>) => (
      <BottomSheetBackdrop
        {...props}
        appearsOnIndex={0}
        disappearsOnIndex={-1}
        pressBehavior="close"
      />
    ),
    [],
  );

  const openCompartmentSheet = React.useCallback((compartment: CompartmentEntry) => {
    setModalError(null);
    setModalInfo(null);
    setSelectedCompartment(compartment);
    requestAnimationFrame(() => {
      compartmentSheetRef.current?.present();
    });
  }, []);

  const closeCompartmentSheet = React.useCallback(() => {
    compartmentSheetRef.current?.dismiss();
  }, []);

  if (isLoading && !data) {
    return (
      <SafeAreaView style={[styles.safe, { backgroundColor: theme.colors.background }]} edges={[]}>
        <View style={styles.center}>
          <ActivityIndicator />
          <Text style={styles.centerText}>Loading compartments…</Text>
        </View>
      </SafeAreaView>
    );
  }

  const sections = mapSections(data);
  const visibleSections =
    selectedLockerBankId === 'all'
      ? sections
      : sections.filter((section) => section.id === selectedLockerBankId);
  const errorMessage =
    error && 'status' in error ? `Failed to load compartments (${String(error.status)}).` : null;
  const selectedCompartmentStatus = selectedCompartment
    ? getFakeCompartmentStatus(selectedCompartment)
    : null;
  const selectedCompartmentStatusLabel =
    selectedCompartmentStatus === 'open'
      ? 'Offen'
      : selectedCompartmentStatus === 'closed'
        ? 'Geschlossen'
        : 'Unbekannt';
  const selectedCompartmentStatusColor =
    selectedCompartmentStatus === 'open'
      ? theme.colors.primary
      : selectedCompartmentStatus === 'closed'
        ? theme.colors.onSurfaceVariant
        : '#B7791F';
  const selectedCompartmentStatusBorderColor =
    selectedCompartmentStatus === 'open'
      ? theme.colors.primary
      : selectedCompartmentStatus === 'closed'
        ? theme.colors.outline
        : '#D69E2E';
  const selectedCompartmentStatusBackgroundColor =
    selectedCompartmentStatus === 'open'
      ? theme.colors.primaryContainer
      : selectedCompartmentStatus === 'closed'
        ? theme.colors.surfaceVariant
        : '#FFF6E8';

  return (
    <SafeAreaView style={[styles.safe, { backgroundColor: theme.colors.background }]} edges={[]}>
      <View style={styles.bankFilterRow}>
        <Chip
          selected={selectedLockerBankId === 'all'}
          onPress={() => setSelectedLockerBankId('all')}
          style={[
            styles.bankChip,
            {
              backgroundColor:
                selectedLockerBankId === 'all'
                  ? theme.colors.primaryContainer
                  : theme.colors.background,
              borderColor:
                selectedLockerBankId === 'all' ? theme.colors.primary : theme.colors.outlineVariant,
            },
          ]}
          selectedColor={theme.colors.onPrimaryContainer}
          textStyle={[
            styles.bankChipText,
            {
              color:
                selectedLockerBankId === 'all'
                  ? theme.colors.onPrimaryContainer
                  : theme.colors.onSurfaceVariant,
            },
          ]}
          compact
          showSelectedCheck={false}
        >
          Alle Schränke
        </Chip>
        {sections.map((section) => {
          const lockerStatus = getFakeLockerStatus(section.id);
          const isSelected = selectedLockerBankId === section.id;
          const lockerTextColor = isSelected
            ? theme.colors.onPrimaryContainer
            : lockerStatus === 'offline'
              ? '#A34747'
              : theme.colors.onSurfaceVariant;

          return (
            <Chip
              key={section.id}
              selected={isSelected}
              onPress={() => setSelectedLockerBankId(section.id)}
              style={[
                styles.bankChip,
                {
                  backgroundColor: isSelected
                    ? theme.colors.primaryContainer
                    : lockerStatus === 'offline'
                      ? '#FFF3F3'
                      : theme.colors.background,
                  borderColor: isSelected
                    ? theme.colors.primary
                    : lockerStatus === 'offline'
                      ? '#E08A8A'
                      : theme.colors.outlineVariant,
                },
              ]}
              selectedColor={theme.colors.onPrimaryContainer}
              textStyle={[
                styles.bankChipText,
                {
                  color: lockerTextColor,
                },
              ]}
              compact
              showSelectedCheck={false}
              icon={
                lockerStatus === 'offline'
                  ? ({ size }) => <WifiOff size={size} color={lockerTextColor} strokeWidth={2.2} />
                  : undefined
              }
            >
              {section.title}
            </Chip>
          );
        })}
      </View>
      {errorMessage ? (
        <Text style={styles.error} accessibilityRole="alert">
          {errorMessage}
        </Text>
      ) : null}

      <SectionList
        sections={visibleSections}
        keyExtractor={(item) => item.id}
        contentInsetAdjustmentBehavior="never"
        stickySectionHeadersEnabled={false}
        refreshControl={
          <RefreshControl
            refreshing={isFetching && !isLoading}
            onRefresh={() => {
              void refetchCompartments();
            }}
          />
        }
        contentContainerStyle={[styles.listContent, { paddingBottom: insets.bottom + 24 }]}
        renderItem={({ item }) => {
          const isEmpty = !item.item;
          const storedItemName = item.item?.name?.trim();
          const compartmentStatus = getFakeCompartmentStatus(item);
          const statusLabel =
            compartmentStatus === 'open'
              ? 'Offen'
              : compartmentStatus === 'closed'
                ? 'Geschlossen'
                : 'Unbekannt';
          const statusColor =
            compartmentStatus === 'open'
              ? theme.colors.primary
              : compartmentStatus === 'closed'
                ? theme.colors.onSurfaceVariant
                : '#B7791F';
          const statusBorderColor =
            compartmentStatus === 'open'
              ? theme.colors.primary
              : compartmentStatus === 'closed'
                ? theme.colors.outline
                : '#D69E2E';
          const statusBackgroundColor =
            compartmentStatus === 'open'
              ? theme.colors.primaryContainer
              : compartmentStatus === 'closed'
                ? theme.colors.surfaceVariant
                : '#FFF6E8';

          return (
            <View style={styles.cardWrap}>
              <Pressable
                onPress={() => openCompartmentSheet(item)}
                style={({ pressed }) => [pressed && styles.cardPressed]}
              >
                <Card
                  mode="contained"
                  style={[
                    styles.card,
                    {
                      backgroundColor: theme.colors.surface,
                      borderColor: theme.colors.outlineVariant,
                    },
                  ]}
                >
                  <Card.Content style={styles.cardContent}>
                    <View style={styles.cardRow}>
                      <View
                        style={[
                          styles.numberBadge,
                          {
                            backgroundColor: isEmpty
                              ? theme.colors.surfaceVariant
                              : theme.colors.primaryContainer,
                          },
                        ]}
                      >
                        <Text style={styles.numberLabel}>#{item.number}</Text>
                      </View>

                      <View style={styles.cardMain}>
                        <View style={styles.cardHeaderRow}>
                          <Text variant="titleMedium" numberOfLines={1} style={styles.cardTitle}>
                            Compartment {item.number}
                          </Text>
                          <View
                            style={[
                              styles.statusPill,
                              {
                                borderColor: statusBorderColor,
                                backgroundColor: statusBackgroundColor,
                              },
                            ]}
                          >
                            {compartmentStatus === 'open' ? (
                              <LockOpen size={12} color={statusColor} />
                            ) : compartmentStatus === 'closed' ? (
                              <Lock size={12} color={statusColor} />
                            ) : (
                              <CircleHelp size={12} color={statusColor} />
                            )}
                            <Text style={[styles.statusPillText, { color: statusColor }]}>
                              {statusLabel}
                            </Text>
                          </View>
                        </View>

                        <Text variant="bodySmall" numberOfLines={1} style={styles.cardSubtitle}>
                          {isEmpty
                            ? 'No item currently stored'
                            : `Contains: ${storedItemName ?? 'Unnamed item'}`}
                        </Text>
                      </View>
                    </View>
                  </Card.Content>
                </Card>
              </Pressable>
            </View>
          );
        }}
        ListEmptyComponent={
          <View style={styles.center}>
            <Text>No compartments found.</Text>
          </View>
        }
      />
      <BottomSheetModal
        ref={compartmentSheetRef}
        index={0}
        snapPoints={sheetSnapPoints}
        enableDynamicSizing={false}
        onDismiss={() => setSelectedCompartment(null)}
        backdropComponent={sheetBackdrop}
        enablePanDownToClose
        handleIndicatorStyle={[styles.bottomSheetHandle, { backgroundColor: theme.colors.outline }]}
        backgroundStyle={[
          styles.bottomSheetBackground,
          {
            backgroundColor: theme.colors.surface,
            borderColor: theme.colors.outlineVariant,
          },
        ]}
      >
        <BottomSheetView
          style={styles.bottomSheetContent}
          onLayout={(event) => {
            const nextHeight = Math.ceil(event.nativeEvent.layout.height) + 20;
            if (Math.abs(nextHeight - sheetContentHeight) > 2) {
              setSheetContentHeight(nextHeight);
            }
          }}
        >
          {selectedCompartment ? (
            <View style={styles.sheetStatusRow}>
              <Text variant="bodySmall" style={styles.modalSubtitle}>
                Fachstatus (Demo):
              </Text>
              <View
                style={[
                  styles.statusPill,
                  styles.sheetStatusPill,
                  {
                    borderColor: selectedCompartmentStatusBorderColor,
                    backgroundColor: selectedCompartmentStatusBackgroundColor,
                  },
                ]}
              >
                {selectedCompartmentStatus === 'open' ? (
                  <LockOpen size={12} color={selectedCompartmentStatusColor} />
                ) : selectedCompartmentStatus === 'closed' ? (
                  <Lock size={12} color={selectedCompartmentStatusColor} />
                ) : (
                  <CircleHelp size={12} color={selectedCompartmentStatusColor} />
                )}
                <Text style={[styles.statusPillText, { color: selectedCompartmentStatusColor }]}>
                  {selectedCompartmentStatusLabel}
                </Text>
              </View>
            </View>
          ) : null}
          <Text variant="titleMedium" style={styles.modalTitle}>
            Compartment {selectedCompartment?.number ?? ''}
          </Text>
          <Text variant="bodyMedium" style={styles.modalSubtitle}>
            Im Fach wird gelagert:
          </Text>
          <View style={[styles.sheetStoredItemCard, { borderColor: theme.colors.outlineVariant }]}>
            <Text variant="titleSmall" style={styles.modalStoredItemName}>
              {selectedCompartment?.item?.name?.trim() || 'Aktuell kein Item'}
            </Text>
            {!!selectedCompartment?.item?.description?.trim() && (
              <Text variant="bodyMedium" style={styles.modalStoredItemDescription}>
                {selectedCompartment.item.description.trim()}
              </Text>
            )}
          </View>
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
                  closeCompartmentSheet();
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
          <Button mode="text" onPress={closeCompartmentSheet}>
            Close
          </Button>
        </BottomSheetView>
      </BottomSheetModal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1 },
  listContent: { paddingTop: 10 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 12 },
  centerText: { opacity: 0.7 },
  bankFilterRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 16,
    paddingTop: 10,
    paddingBottom: 10,
    flexWrap: 'wrap',
  },
  bankChip: {
    borderRadius: 999,
    borderWidth: 1,
  },
  bankChipText: {
    fontFamily: 'Inter_500Medium',
  },
  error: { color: '#b00020', paddingHorizontal: 16, paddingTop: 12 },
  cardWrap: { paddingHorizontal: 16, paddingBottom: 12 },
  card: { width: '100%', borderRadius: 16, borderWidth: 1 },
  cardPressed: {
    opacity: 0.9,
    transform: [{ scale: 0.995 }],
  },
  cardContent: { paddingVertical: 10 },
  cardRow: { flexDirection: 'row', gap: 12, alignItems: 'center' },
  numberBadge: {
    width: 62,
    height: 62,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
  },
  numberLabel: {
    fontFamily: 'Inter_700Bold',
    fontSize: 24,
    lineHeight: 28,
    letterSpacing: -0.2,
  },
  cardMain: { flex: 1, minWidth: 0, gap: 3 },
  cardHeaderRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
  },
  cardTitle: { fontWeight: '700', fontFamily: 'Inter_600SemiBold' },
  cardSubtitle: { opacity: 0.7, marginBottom: 2, fontFamily: 'Inter_500Medium' },
  statusPill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    borderRadius: 999,
    borderWidth: 1,
    paddingHorizontal: 7,
    paddingVertical: 3,
  },
  statusPillText: {
    fontSize: 11,
    fontFamily: 'Inter_500Medium',
  },
  bottomSheetBackground: {
    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,
    borderWidth: 1,
  },
  bottomSheetContent: {
    paddingHorizontal: 16,
    paddingTop: 2,
    paddingBottom: 16,
    gap: 8,
  },
  bottomSheetHandle: {
    width: 38,
    height: 5,
    borderRadius: 999,
  },
  sheetStatusRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 2,
    width: '100%',
  },
  sheetStatusPill: {
    paddingVertical: 4,
  },
  sheetStatusText: {
    opacity: 0.92,
    fontFamily: 'Inter_500Medium',
  },
  modalTitle: { fontWeight: '700' },
  modalSubtitle: { opacity: 0.8, marginTop: 2 },
  sheetStoredItemCard: {
    borderWidth: 1,
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 10,
    gap: 4,
  },
  modalStoredItemName: { fontFamily: 'Inter_600SemiBold' },
  modalStoredItemDescription: { opacity: 0.84, lineHeight: 20 },
  modalHint: { opacity: 0.7, marginBottom: 6 },
});
