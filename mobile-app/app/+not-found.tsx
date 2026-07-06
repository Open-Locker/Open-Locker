import { Link, Stack } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { StyleSheet, View } from 'react-native';
import { Text, useTheme } from 'react-native-paper';

export default function NotFoundScreen() {
  const { t } = useTranslation();
  const theme = useTheme();

  return (
    <>
      <Stack.Screen options={{ title: t('misc.oops') }} />
      <View style={[styles.container, { backgroundColor: theme.colors.background }]}>
        <Text variant="titleLarge" style={styles.title}>
          {t('misc.screenNotFound')}
        </Text>

        <Link href="/" style={styles.link}>
          <Text style={[styles.linkText, { color: theme.colors.primary }]}>{t('misc.goHome')}</Text>
        </Link>
      </View>
    </>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 20,
  },
  title: {
    fontWeight: 'bold',
  },
  link: {
    marginTop: 15,
    paddingVertical: 15,
  },
  linkText: {
    fontSize: 14,
  },
});
