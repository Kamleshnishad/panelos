import { useEffect } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { useSessionStore } from '../auth/session';
import { readToken, clearToken } from '../auth/storage';
import { me } from '../api/auth';
import { LoginScreen } from '../screens/LoginScreen';
import { StaffTabs } from './StaffTabs';
import { colors } from '../design-system/tokens';

// On boot: don't trust a stored token blindly — validate it against /auth/me
// before showing any authenticated screen (mirrors the web app's session
// behavior; a 12h-expired token should bounce straight back to login).
function useSessionRestore() {
  const status = useSessionStore((s) => s.status);
  const setStatus = useSessionStore((s) => s.setStatus);
  const setToken = useSessionStore((s) => s.setToken);
  const setUser = useSessionStore((s) => s.setUser);
  const clearSession = useSessionStore((s) => s.clearSession);

  useEffect(() => {
    if (status !== 'idle') return;
    (async () => {
      setStatus('restoring');
      const token = await readToken();
      if (!token) {
        setStatus('unauthenticated');
        return;
      }
      setToken(token);
      try {
        const res = await me();
        setUser(res.data);
      } catch {
        await clearToken();
        clearSession();
      }
    })();
  }, [status, setStatus, setToken, setUser, clearSession]);
}

export function RootNavigator() {
  useSessionRestore();
  const status = useSessionStore((s) => s.status);

  if (status === 'idle' || status === 'restoring') {
    return (
      <View style={styles.loading}>
        <ActivityIndicator size="large" color={colors.primary} />
      </View>
    );
  }

  return <NavigationContainer>{status === 'authenticated' ? <StaffTabs /> : <LoginScreen />}</NavigationContainer>;
}

const styles = StyleSheet.create({
  loading: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg },
});
