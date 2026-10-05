import { StyleSheet, Text, View } from 'react-native';
import { font, pillColors, PillVariant, radius, spacing } from './tokens';

type Props = {
  label: string;
  variant: PillVariant;
};

export function StatusPill({ label, variant }: Props) {
  const { bg, fg } = pillColors[variant];
  return (
    <View style={[styles.pill, { backgroundColor: bg }]}>
      <Text style={[styles.label, { color: fg }]}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  pill: {
    paddingHorizontal: spacing.md - 2,
    paddingVertical: 4,
    borderRadius: radius.pill,
    alignSelf: 'flex-start',
  },
  label: {
    fontSize: font.size.xs + 0.5,
    fontWeight: font.weight.bold,
    letterSpacing: 0.2,
  },
});
