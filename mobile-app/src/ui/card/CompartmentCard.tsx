import React from 'react';
import { Pressable, StyleSheet, Text as RNText, View } from 'react-native';
import { CircleHelp, Lock, LockOpen } from 'lucide-react-native';
import { useTranslation } from 'react-i18next';
import { Card, Text, useTheme } from 'react-native-paper';

import type { GetCompartmentsAccessibleApiResponse } from '@/src/store/generatedApi';
import {
  getCompartmentStatusPalette,
  type CompartmentVisualStatus,
} from '@/src/theme/statusPalette';

type LockerBank = GetCompartmentsAccessibleApiResponse['locker_banks'][number];
type CompartmentEntry = LockerBank['compartments'][number];

type CompartmentCardProps = {
  compartment: CompartmentEntry;
  status?: CompartmentVisualStatus | null;
  onPress: () => void;
};

export function CompartmentCard({ compartment, status, onPress }: CompartmentCardProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const isEmpty = !compartment.item;
  const storedItemName = compartment.item?.name?.trim();
  const displayNumber = String(compartment.number).padStart(2, '0');
  const statusLabel =
    status === 'open'
      ? t('compartments.statusOpen')
      : status === 'closed'
        ? t('compartments.statusClosed')
        : t('compartments.statusUnknown');
  const statusPalette = status ? getCompartmentStatusPalette(theme, status) : null;
  const cardTextColor = status === 'open' ? '#0f2a75' : '#0f172a';
  const subtitleTextColor =
    status === 'open' ? theme.colors.onPrimaryContainer : theme.colors.onSurfaceVariant;

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.pressable, pressed && styles.cardPressed]}
    >
      <Card
        mode="contained"
        style={[
          styles.card,
          {
            backgroundColor:
              status === 'open' ? theme.colors.primaryContainer : theme.colors.surface,
            borderColor: status === 'open' ? theme.colors.primary : theme.colors.outlineVariant,
          },
        ]}
      >
        <Card.Content style={styles.cardContent}>
          <View style={styles.mainContent}>
            <RNText style={[styles.code, { color: cardTextColor }]}>{displayNumber}</RNText>
            <Text
              variant="bodySmall"
              numberOfLines={2}
              style={[styles.subtitle, { color: subtitleTextColor }]}
            >
              {isEmpty
                ? t('compartments.currentlyEmpty')
                : (storedItemName ?? t('compartments.unnamedItem'))}
            </Text>
          </View>
          {status && statusPalette ? (
            <View style={styles.statusPillWrap}>
              <View
                style={[
                  styles.statusPill,
                  {
                    borderColor: statusPalette.borderColor,
                    backgroundColor: statusPalette.backgroundColor,
                  },
                ]}
              >
                {status === 'open' ? (
                  <LockOpen size={12} color={statusPalette.color} />
                ) : status === 'closed' ? (
                  <Lock size={12} color={statusPalette.color} />
                ) : (
                  <CircleHelp size={12} color={statusPalette.color} />
                )}
                <Text style={[styles.statusPillText, { color: statusPalette.color }]}>
                  {statusLabel}
                </Text>
              </View>
            </View>
          ) : null}
        </Card.Content>
      </Card>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  pressable: {
    flex: 1,
  },
  card: {
    height: 170,
    borderRadius: 18,
    borderWidth: 1,
  },
  cardPressed: {
    opacity: 0.9,
    transform: [{ scale: 0.985 }],
  },
  cardContent: {
    height: '100%',
    paddingVertical: 12,
    paddingHorizontal: 12,
  },
  mainContent: {
    gap: 6,
    position: 'relative',
    paddingTop: 56,
  },
  statusPillWrap: {
    marginTop: 'auto',
    alignSelf: 'flex-end',
  },
  code: {
    fontFamily: 'Inter_700Bold',
    fontSize: 56,
    lineHeight: 56,
    position: 'absolute',
    top: 0,
    left: 0,
    letterSpacing: -1.2,
    textShadowColor: 'rgba(0,0,0,0.12)',
    textShadowOffset: { width: 0, height: 1 },
    textShadowRadius: 1,
  },
  subtitle: {
    fontFamily: 'Inter_500Medium',
    lineHeight: 19,
    minHeight: 36,
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
});
