import { SafeAreaView, StyleSheet, Text, View } from 'react-native';
import { colors, font, spacing } from '../design-system/tokens';

// Stand-in for Dashboard/Production/Dispatch/Sales screens — Phase 0 only builds
// the shell; real content lands in mobile_plan/01_PHASE_ROADMAP.md Phases 1-3.
export function PlaceholderScreen({ title, subtitle }: { title: string; subtitle: string }) {
  return (
    <SafeAreaView style={styles.screen}>
      <View style={styles.topbar}>
        <Text style={styles.topbarTitle}>{title}</Text>
      </View>
      <View style={styles.content}>
        <Text style={styles.subtitle}>{subtitle}</Text>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  topbar: { minHeight: 56, backgroundColor: colors.primary, justifyContent: 'center', paddingHorizontal: 14 },
  topbarTitle: { color: colors.white, fontSize: font.size.lg, fontWeight: font.weight.bold },
  content: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  subtitle: { color: colors.textSecondary, fontWeight: font.weight.semibold, textAlign: 'center' },
});
