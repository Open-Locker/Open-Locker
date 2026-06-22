import React from 'react';
import { Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { useTranslation } from 'react-i18next';
import { SegmentedButtons, Text, useTheme } from 'react-native-paper';

import { getCurrentAppLanguage, setAppLanguage } from '@/src/i18n';
import type { AppLanguage } from '@/src/i18n/resources';

const LANGUAGES: readonly AppLanguage[] = ['en', 'de'];

type LanguageToggleProps = {
  /**
   * `segmented` — full SegmentedButtons control (account/settings screen).
   * `inline` — compact `EN | DE` text switch for pre-auth screens.
   */
  variant?: 'segmented' | 'inline';
  style?: StyleProp<ViewStyle>;
};

/**
 * App-language switch shared across authenticated and pre-auth screens.
 * Changing it persists the choice and updates the `Accept-Language` header
 * sent on every API request (ADR-0024).
 */
export function LanguageToggle({ variant = 'segmented', style }: LanguageToggleProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  // useTranslation subscribes this component to language changes, so reading
  // the current value during render stays in sync after a switch.
  const value = getCurrentAppLanguage();

  const change = React.useCallback((next: AppLanguage) => {
    void setAppLanguage(next);
  }, []);

  if (variant === 'inline') {
    return (
      <View style={[styles.inline, style]}>
        {LANGUAGES.map((lang, index) => {
          const active = lang === value;
          return (
            <React.Fragment key={lang}>
              {index > 0 && (
                <Text
                  variant="bodySmall"
                  style={[styles.separator, { color: theme.colors.outlineVariant }]}
                >
                  |
                </Text>
              )}
              <Pressable
                onPress={() => change(lang)}
                accessibilityRole="button"
                accessibilityState={{ selected: active }}
                hitSlop={8}
              >
                <Text
                  variant="bodySmall"
                  style={[
                    styles.inlineLabel,
                    {
                      color: active ? theme.colors.primary : theme.colors.onSurfaceVariant,
                      opacity: active ? 1 : 0.7,
                    },
                  ]}
                >
                  {lang.toUpperCase()}
                </Text>
              </Pressable>
            </React.Fragment>
          );
        })}
      </View>
    );
  }

  return (
    <SegmentedButtons
      value={value}
      onValueChange={(next) => change(next as AppLanguage)}
      density="small"
      style={style}
      buttons={[
        { value: 'en', label: t('account.languageEnglish') },
        { value: 'de', label: t('account.languageGerman') },
      ]}
    />
  );
}

const styles = StyleSheet.create({
  inline: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  separator: {
    opacity: 0.6,
  },
  inlineLabel: {
    fontWeight: '600',
    letterSpacing: 0.5,
  },
});
