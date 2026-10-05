import { ActivityIndicator, GestureResponderEvent, StyleSheet, Text, TouchableOpacity, ViewStyle } from 'react-native';
import { colors, font, radius, spacing } from './tokens';

type Variant = 'primary' | 'secondary' | 'outline' | 'danger' | 'ghost';

type Props = {
  title: string;
  onPress?: (e: GestureResponderEvent) => void;
  variant?: Variant;
  disabled?: boolean;
  loading?: boolean;
  block?: boolean;
  style?: ViewStyle;
};

const variantStyles: Record<Variant, { bg: string; fg: string; border?: string }> = {
  primary: { bg: colors.primary, fg: colors.white },
  secondary: { bg: colors.accentBg, fg: colors.accent },
  outline: { bg: colors.white, fg: colors.primary, border: colors.primary },
  danger: { bg: colors.danger, fg: colors.white },
  ghost: { bg: 'transparent', fg: colors.primary },
};

export function Button({ title, onPress, variant = 'primary', disabled, loading, block, style }: Props) {
  const v = variantStyles[variant];
  return (
    <TouchableOpacity
      onPress={onPress}
      disabled={disabled || loading}
      activeOpacity={0.75}
      style={[
        styles.base,
        { backgroundColor: v.bg, borderColor: v.border ?? 'transparent', borderWidth: v.border ? 1.5 : 0 },
        block && styles.block,
        (disabled || loading) && styles.disabled,
        style,
      ]}
    >
      {loading ? <ActivityIndicator color={v.fg} /> : <Text style={[styles.label, { color: v.fg }]}>{title}</Text>}
    </TouchableOpacity>
  );
}

const styles = StyleSheet.create({
  base: {
    borderRadius: radius.sm + 1,
    paddingVertical: 13,
    paddingHorizontal: spacing.lg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  block: { width: '100%' },
  disabled: { opacity: 0.55 },
  label: { fontSize: font.size.md, fontWeight: font.weight.bold },
});
