import React from 'react';
import { Animated, FlatList, Pressable, RefreshControl, StyleSheet, View } from 'react-native';
import { BottomSheetBackdrop, BottomSheetModal, BottomSheetView } from '@gorhom/bottom-sheet';
import { CircleHelp, CircleUserRound, Lock, LockOpen, WifiOff } from 'lucide-react-native';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';
import { skipToken } from '@reduxjs/toolkit/query';
import { router } from 'expo-router';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { ActivityIndicator, Button, Chip, HelperText, Text, useTheme } from 'react-native-paper';

import {
  type GetCompartmentsAccessibleApiResponse,
  useGetCompartmentsAccessibleQuery,
  usePostCompartmentsByCompartmentOpenMutation,
} from '@/src/store/generatedApi';
import { useAppSelector } from '@/src/store/hooks';
import {
  getCompartmentStatusPalette,
  getLockerStatusPalette,
  type CompartmentVisualStatus,
  type LockerVisualStatus,
} from '@/src/theme/statusPalette';
import { CompartmentCard } from '@/src/ui/card/CompartmentCard';

type LockerBank = GetCompartmentsAccessibleApiResponse['locker_banks'][number];
type CompartmentEntry = LockerBank['compartments'][number];
type LockerBankFilter = {
  id: string;
  title: string;
  compartments: CompartmentEntry[];
};

function mapLockerBanks(
  response: GetCompartmentsAccessibleApiResponse | undefined,
): LockerBankFilter[] {
  if (!response?.locker_banks) return [];
  return response.locker_banks
    .map((bank) => ({
      id: bank.id,
      title: bank.name?.trim() || `Locker bank ${bank.id}`,
      compartments: Array.isArray(bank.compartments) ? bank.compartments : [],
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

function getCompartmentStatusFromApi(
  compartment: CompartmentEntry,
): CompartmentVisualStatus | null {
  const candidate = compartment as CompartmentEntry & {
    state?: unknown;
    status?: unknown;
    is_open?: unknown;
    open?: unknown;
  };

  const rawState = candidate.state ?? candidate.status;
  if (typeof rawState === 'string') {
    const normalized = rawState.trim().toLowerCase();
    if (normalized === 'open') return 'open';
    if (normalized === 'closed') return 'closed';
    if (normalized === 'unknown') return 'unknown';
  }

  if (typeof candidate.is_open === 'boolean') {
    return candidate.is_open ? 'open' : 'closed';
  }
  if (typeof candidate.open === 'boolean') {
    return candidate.open ? 'open' : 'closed';
  }

  return null;
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
  const userName = useAppSelector((state) => state.auth.userName);
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const scrollY = React.useRef(new Animated.Value(0)).current;
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

  const lockerBanks = mapLockerBanks(data);
  const visibleLockerBanks =
    selectedLockerBankId === 'all'
      ? lockerBanks
      : lockerBanks.filter((section) => section.id === selectedLockerBankId);
  const visibleCompartments = visibleLockerBanks
    .flatMap((lockerBank) => lockerBank.compartments)
    .sort((a, b) => a.number - b.number);
  const errorMessage =
    error && 'status' in error ? `Failed to load compartments (${String(error.status)}).` : null;
  const selectedCompartmentStatus = selectedCompartment
    ? getCompartmentStatusFromApi(selectedCompartment)
    : null;
  const accountInitial = (userName?.trim().charAt(0) || 'A').toUpperCase();
  const selectedCompartmentStatusLabel =
    selectedCompartmentStatus === 'open'
      ? 'Open'
      : selectedCompartmentStatus === 'closed'
        ? 'Closed'
        : 'Unknown';
  const selectedStatusPalette = selectedCompartmentStatus
    ? getCompartmentStatusPalette(theme, selectedCompartmentStatus)
    : null;
  const headerMaxHeight = 74;
  const headerTranslateY = scrollY.interpolate({
    inputRange: [0, headerMaxHeight],
    outputRange: [0, -headerMaxHeight],
    extrapolate: 'clamp',
  });
  const headerOpacity = scrollY.interpolate({
    inputRange: [0, headerMaxHeight * 0.8],
    outputRange: [1, 0],
    extrapolate: 'clamp',
  });
  const headerContainerHeight = scrollY.interpolate({
    inputRange: [0, headerMaxHeight],
    outputRange: [headerMaxHeight, 0],
    extrapolate: 'clamp',
  });

  return (
    <SafeAreaView
      style={[styles.safe, { backgroundColor: theme.colors.background }]}
      edges={['top']}
    >
      <Animated.View style={[styles.screenHeaderContainer, { height: headerContainerHeight }]}>
        <Animated.View
          style={[
            styles.screenHeader,
            {
              opacity: headerOpacity,
              transform: [{ translateY: headerTranslateY }],
            },
          ]}
        >
          <View style={styles.screenHeaderTop}>
            <View style={styles.screenHeaderText}>
              <Text style={styles.screenHeading}>Compartment</Text>
              <Text style={styles.screenSubheading}>Manage and open your compartments.</Text>
            </View>
            <Pressable
              onPress={() => router.push('/account' as never)}
              style={({ pressed }) => [styles.profileButton, pressed && styles.cardPressed]}
              accessibilityRole="button"
              accessibilityLabel="Open profile"
            >
              <View
                style={[styles.profileAvatar, { backgroundColor: theme.colors.primaryContainer }]}
              >
                <Text style={[styles.profileInitial, { color: theme.colors.onPrimaryContainer }]}>
                  {accountInitial}
                </Text>
              </View>
              <CircleUserRound size={16} color={theme.colors.onSurfaceVariant} strokeWidth={2.2} />
            </Pressable>
          </View>
        </Animated.View>
      </Animated.View>
      {errorMessage ? (
        <Text style={styles.error} accessibilityRole="alert">
          {errorMessage}
        </Text>
      ) : null}

      <FlatList
        data={visibleCompartments}
        keyExtractor={(item) => item.id}
        numColumns={2}
        columnWrapperStyle={styles.gridRow}
        contentInsetAdjustmentBehavior="never"
        stickyHeaderIndices={[0]}
        onScroll={Animated.event([{ nativeEvent: { contentOffset: { y: scrollY } } }], {
          useNativeDriver: false,
        })}
        scrollEventThrottle={16}
        refreshControl={
          <RefreshControl
            refreshing={isFetching && !isLoading}
            onRefresh={() => {
              void refetchCompartments();
            }}
          />
        }
        contentContainerStyle={[styles.gridContent, { paddingBottom: insets.bottom + 24 }]}
        ListHeaderComponent={
          <View style={[styles.bankFilterRow, { backgroundColor: theme.colors.background }]}>
            <View style={[styles.filterRail, { backgroundColor: theme.colors.surfaceVariant }]}>
              <Chip
                selected={selectedLockerBankId === 'all'}
                onPress={() => setSelectedLockerBankId('all')}
                style={[
                  styles.bankChip,
                  {
                    backgroundColor:
                      selectedLockerBankId === 'all'
                        ? theme.colors.primaryContainer
                        : theme.colors.surfaceVariant,
                    borderColor:
                      selectedLockerBankId === 'all'
                        ? theme.colors.primary
                        : theme.colors.surfaceVariant,
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
                All
              </Chip>
              {lockerBanks.map((section) => {
                const lockerStatus = getFakeLockerStatus(section.id);
                const isSelected = selectedLockerBankId === section.id;
                const lockerStatusPalette = getLockerStatusPalette(theme, lockerStatus, isSelected);

                return (
                  <Chip
                    key={section.id}
                    selected={isSelected}
                    onPress={() => setSelectedLockerBankId(section.id)}
                    style={[
                      styles.bankChip,
                      {
                        backgroundColor: lockerStatusPalette.backgroundColor,
                        borderColor: lockerStatusPalette.borderColor,
                      },
                    ]}
                    selectedColor={theme.colors.onPrimaryContainer}
                    textStyle={[
                      styles.bankChipText,
                      {
                        color: lockerStatusPalette.color,
                      },
                    ]}
                    compact
                    showSelectedCheck={false}
                    icon={
                      lockerStatus === 'offline'
                        ? ({ size }) => (
                            <WifiOff
                              size={size}
                              color={lockerStatusPalette.color}
                              strokeWidth={2.2}
                            />
                          )
                        : undefined
                    }
                  >
                    {section.title}
                  </Chip>
                );
              })}
            </View>
          </View>
        }
        renderItem={({ item }) => {
          const compartmentStatus = getCompartmentStatusFromApi(item);

          return (
            <View style={styles.gridItem}>
              <CompartmentCard
                compartment={item}
                status={compartmentStatus}
                onPress={() => openCompartmentSheet(item)}
              />
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
          {selectedCompartment && selectedCompartmentStatus && selectedStatusPalette ? (
            <View style={styles.sheetStatusRow}>
              <Text variant="bodySmall" style={styles.modalSubtitle}>
                Compartment status:
              </Text>
              <View
                style={[
                  styles.statusPill,
                  styles.sheetStatusPill,
                  {
                    borderColor: selectedStatusPalette.borderColor,
                    backgroundColor: selectedStatusPalette.backgroundColor,
                  },
                ]}
              >
                {selectedCompartmentStatus === 'open' ? (
                  <LockOpen size={12} color={selectedStatusPalette.color} />
                ) : selectedCompartmentStatus === 'closed' ? (
                  <Lock size={12} color={selectedStatusPalette.color} />
                ) : (
                  <CircleHelp size={12} color={selectedStatusPalette.color} />
                )}
                <Text style={[styles.statusPillText, { color: selectedStatusPalette.color }]}>
                  {selectedCompartmentStatusLabel}
                </Text>
              </View>
            </View>
          ) : null}
          <Text variant="titleMedium" style={styles.modalTitle}>
            Compartment {selectedCompartment?.number ?? ''}
          </Text>
          <Text variant="bodyMedium" style={styles.modalSubtitle}>
            Stored item:
          </Text>
          <View style={[styles.sheetStoredItemCard, { borderColor: theme.colors.outlineVariant }]}>
            <Text variant="titleSmall" style={styles.modalStoredItemName}>
              {selectedCompartment?.item?.name?.trim() || 'No item currently stored'}
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
  screenHeaderContainer: {
    overflow: 'hidden',
  },
  screenHeader: {
    paddingHorizontal: 16,
    paddingTop: 8,
    paddingBottom: 8,
    gap: 2,
  },
  screenHeaderTop: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 10,
  },
  screenHeaderText: {
    flex: 1,
  },
  screenHeading: {
    fontFamily: 'Inter_700Bold',
    fontSize: 32,
    lineHeight: 36,
    letterSpacing: -0.9,
  },
  screenSubheading: {
    fontFamily: 'Inter_500Medium',
    opacity: 0.8,
    fontSize: 13,
    lineHeight: 18,
  },
  profileButton: {
    height: 38,
    borderRadius: 999,
    paddingHorizontal: 8,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 5,
  },
  profileAvatar: {
    width: 24,
    height: 24,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  profileInitial: {
    fontFamily: 'Inter_600SemiBold',
    fontSize: 12,
  },
  gridContent: {
    paddingTop: 8,
    paddingHorizontal: 16,
    gap: 10,
  },
  gridRow: {
    gap: 10,
    marginBottom: 10,
  },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 12 },
  centerText: { opacity: 0.7 },
  bankFilterRow: {
    paddingBottom: 8,
  },
  filterRail: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    borderRadius: 12,
    padding: 4,
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
  gridItem: {
    flex: 1,
  },
  cardPressed: {
    opacity: 0.9,
    transform: [{ scale: 0.985 }],
  },
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
