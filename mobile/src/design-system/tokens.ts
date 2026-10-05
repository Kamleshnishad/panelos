// Ported from the approved Artifact mockup's theme.css (mobile_plan/13_DESIGN_SYSTEM_UI_GUIDELINES.md).
// Keep these in sync with theme.css if the mockup's palette ever changes.

export const colors = {
  primary: '#1a237e',
  primaryDark: '#10164f',
  secondary: '#3949ab',
  success: '#2e7d32',
  successBg: '#e8f5e9',
  danger: '#c62828',
  dangerBg: '#fdecea',
  warning: '#f57f17',
  warningBg: '#fff8e1',
  orange: '#e65100',
  orangeBg: '#fbe9e0',
  accent: '#4527a0',
  accentBg: '#ede7f6',
  bg: '#f2f3f7',
  surface: '#ffffff',
  border: '#e2e4e9',
  text: '#1c1e21',
  textSecondary: '#6b7280',
  tabInactive: '#9aa0ac',
  white: '#ffffff',
} as const;

export const radius = {
  sm: 10,
  md: 14,
  pill: 999,
} as const;

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

export const font = {
  size: {
    xs: 11,
    sm: 12.5,
    md: 14.5,
    lg: 17,
    xl: 22,
  },
  weight: {
    regular: '500' as const,
    semibold: '600' as const,
    bold: '700' as const,
    heavy: '800' as const,
  },
};

export type PillVariant = 'success' | 'danger' | 'warning' | 'orange' | 'accent' | 'neutral';

export const pillColors: Record<PillVariant, { bg: string; fg: string }> = {
  success: { bg: colors.successBg, fg: colors.success },
  danger: { bg: colors.dangerBg, fg: colors.danger },
  warning: { bg: colors.warningBg, fg: colors.warning },
  orange: { bg: colors.orangeBg, fg: colors.orange },
  accent: { bg: colors.accentBg, fg: colors.accent },
  neutral: { bg: '#eceff1', fg: '#546e7a' },
};
