import { useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, SafeAreaView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Button } from '../design-system/Button';
import { colors, font, radius, spacing } from '../design-system/tokens';
import { login, me } from '../api/auth';
import { saveToken } from '../auth/storage';
import { useSessionStore } from '../auth/session';

export function LoginScreen() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const setToken = useSessionStore((s) => s.setToken);
  const setUser = useSessionStore((s) => s.setUser);

  async function handleSignIn() {
    setError(null);
    if (!email || !password) {
      setError('Enter both email and password.');
      return;
    }
    setSubmitting(true);
    try {
      const loginRes = await login(email.trim(), password);
      if ('needs_2fa' in loginRes.data) {
        // Two-factor email OTP is not yet built into the mobile app (Phase 0 scope) —
        // fail clearly rather than silently mis-handling an undefined token.
        setError('This account has two-factor login enabled. 2FA isn’t supported in the app yet — please sign in on the web.');
        return;
      }
      const token = loginRes.data.token;
      await saveToken(token);
      setToken(token);
      const meRes = await me();
      setUser(meRes.data);
    } catch (e: any) {
      const message = e?.response?.data?.message ?? 'Could not sign in. Check your connection and try again.';
      setError(message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <SafeAreaView style={styles.screen}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <View style={styles.content}>
          <View style={styles.brandBlock}>
            <View style={styles.brandmark}>
              <Text style={styles.brandmarkText}>P</Text>
            </View>
            <Text style={styles.appName}>PanelOS</Text>
            <Text style={styles.appTagline}>Signature PUF Panel LLP</Text>
          </View>

          <View style={styles.card}>
            <Text style={styles.cardTitle}>Staff Sign In</Text>

            <View style={styles.field}>
              <Text style={styles.fieldLabel}>Email or phone</Text>
              <TextInput
                style={styles.input}
                value={email}
                onChangeText={setEmail}
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="email-address"
                placeholder="you@company.com"
                placeholderTextColor={colors.textSecondary}
              />
            </View>

            <View style={styles.field}>
              <Text style={styles.fieldLabel}>Password</Text>
              <TextInput
                style={styles.input}
                value={password}
                onChangeText={setPassword}
                secureTextEntry
                placeholder="••••••••"
                placeholderTextColor={colors.textSecondary}
              />
            </View>

            {error ? <Text style={styles.error}>{error}</Text> : null}

            <Button title="Sign In" variant="primary" block onPress={handleSignIn} loading={submitting} />
          </View>

          <Button
            title="Dealer / Customer Portal"
            variant="outline"
            block
            onPress={() => Alert.alert('Coming soon', 'The Partner Portal ships in a later phase.')}
          />
        </View>
        <Text style={styles.footer}>Session secured · Sanctum token</Text>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  flex: { flex: 1 },
  content: { flex: 1, justifyContent: 'center', paddingHorizontal: spacing.xxl, gap: spacing.xl },
  brandBlock: { alignItems: 'center', gap: spacing.md },
  brandmark: {
    width: 56,
    height: 56,
    borderRadius: 16,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  brandmarkText: { color: colors.white, fontSize: 26, fontWeight: font.weight.heavy },
  appName: { fontSize: font.size.xl, fontWeight: font.weight.heavy, color: colors.primary },
  appTagline: { fontSize: font.size.sm, color: colors.textSecondary, fontWeight: font.weight.semibold },
  card: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    padding: spacing.lg,
    gap: spacing.md,
  },
  cardTitle: { fontSize: 16, fontWeight: font.weight.heavy, color: colors.text },
  field: { gap: 6 },
  fieldLabel: { fontSize: font.size.xs + 1, fontWeight: font.weight.bold, color: colors.textSecondary },
  input: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.sm,
    paddingHorizontal: 13,
    paddingVertical: 11,
    fontSize: font.size.md,
    color: colors.text,
    backgroundColor: '#fbfbfd',
  },
  error: { color: colors.danger, fontSize: font.size.sm, fontWeight: font.weight.semibold },
  footer: { textAlign: 'center', paddingVertical: spacing.md, fontSize: 11, color: colors.textSecondary, fontWeight: font.weight.semibold },
});
