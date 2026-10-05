import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { Button } from '../design-system/Button';
import { Card } from '../design-system/Card';
import { StatusPill } from '../design-system/StatusPill';
import { colors, font, spacing } from '../design-system/tokens';
import { IconAlertTriangle, IconBell, IconCamera, IconCheckCircle, IconHome, IconPackage, IconRupeeCircle, IconTruck, IconUser } from '../design-system/icons';

// Dev-only reference screen (Phase 0.3 exit criteria): every base component in
// one place to diff against the Artifact mockup, so future screens pull from a
// real, current source of truth instead of guessing spacing/color from memory.
export function ComponentGalleryScreen() {
  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <Text style={styles.section}>Buttons</Text>
      <Button title="Primary" variant="primary" onPress={() => {}} />
      <Button title="Secondary" variant="secondary" onPress={() => {}} />
      <Button title="Outline" variant="outline" onPress={() => {}} />
      <Button title="Danger" variant="danger" onPress={() => {}} />
      <Button title="Ghost" variant="ghost" onPress={() => {}} />
      <Button title="Loading" variant="primary" loading onPress={() => {}} />

      <Text style={styles.section}>Status Pills</Text>
      <View style={styles.row}>
        <StatusPill label="Success" variant="success" />
        <StatusPill label="Danger" variant="danger" />
        <StatusPill label="Warning" variant="warning" />
        <StatusPill label="Orange" variant="orange" />
        <StatusPill label="Accent" variant="accent" />
        <StatusPill label="Neutral" variant="neutral" />
      </View>

      <Text style={styles.section}>Card</Text>
      <Card>
        <Text style={{ fontWeight: font.weight.bold }}>PB-2026-0142</Text>
        <Text style={{ color: colors.textSecondary }}>ORD-2026-018 · Om Sai Industries</Text>
      </Card>

      <Text style={styles.section}>Icons</Text>
      <View style={styles.row}>
        <IconHome color={colors.primary} />
        <IconPackage color={colors.primary} />
        <IconTruck color={colors.primary} />
        <IconRupeeCircle color={colors.primary} />
        <IconUser color={colors.primary} />
        <IconBell color={colors.primary} />
        <IconCheckCircle />
        <IconAlertTriangle />
        <IconCamera />
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  section: { fontSize: 12, fontWeight: font.weight.heavy, color: colors.textSecondary, textTransform: 'uppercase', marginTop: spacing.md },
  row: { flexDirection: 'row', gap: spacing.md, flexWrap: 'wrap', alignItems: 'center' },
});
