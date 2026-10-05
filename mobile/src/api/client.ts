import axios from 'axios';
import { useSessionStore } from '../auth/session';
import { API_BASE_URL } from '../shared/env';

// Mirrors frontend/src/services/api.js's shape (interceptor-based auth header,
// 401-triggers-logout), swapping localStorage for the in-memory session store
// (token itself is persisted separately via expo-secure-store, see auth/storage.ts).
export const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
});

apiClient.interceptors.request.use((config) => {
  const token = useSessionStore.getState().token;
  if (token) {
    config.headers = config.headers ?? {};
    (config.headers as Record<string, string>).Authorization = `Bearer ${token}`;
  }
  return config;
});

apiClient.interceptors.response.use(
  (res) => res,
  (error) => {
    const status = error?.response?.status;
    const url: string = error?.config?.url ?? '';
    const hadToken = !!useSessionStore.getState().token;
    if (status === 401 && hadToken && !url.includes('/auth/login')) {
      useSessionStore.getState().clearSession();
    }
    return Promise.reject(error);
  }
);
