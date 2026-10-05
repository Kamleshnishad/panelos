import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'panelos_auth_token';

// expo-secure-store uses Keychain (iOS) / Keystore (Android) — hardware-backed,
// unlike AsyncStorage, which is unsuitable for auth tokens (mobile_plan/12_SECURITY_COMPLIANCE.md).
export async function saveToken(token: string): Promise<void> {
  await SecureStore.setItemAsync(TOKEN_KEY, token);
}

export async function readToken(): Promise<string | null> {
  return SecureStore.getItemAsync(TOKEN_KEY);
}

export async function clearToken(): Promise<void> {
  await SecureStore.deleteItemAsync(TOKEN_KEY);
}
