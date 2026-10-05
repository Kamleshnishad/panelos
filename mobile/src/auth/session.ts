import { create } from 'zustand';

export type SessionUser = {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  company_id: number;
  is_super_admin: boolean;
  is_company_admin: boolean;
  is_admin: boolean;
  role: string | null;
  permissions: string[];
  company: { id: number; name: string; gstin: string | null; state_code: string | null } | null;
};

type Status = 'idle' | 'restoring' | 'authenticated' | 'unauthenticated';

type SessionState = {
  token: string | null;
  user: SessionUser | null;
  status: Status;
  setToken: (token: string) => void;
  setUser: (user: SessionUser) => void;
  clearSession: () => void;
  setStatus: (status: Status) => void;
};

// In-memory session, source of truth for the API client's auth header.
// Persisted copy lives in expo-secure-store (see auth/storage.ts) and is
// used only to restore this store on app boot — never read per-request.
export const useSessionStore = create<SessionState>((set) => ({
  token: null,
  user: null,
  status: 'idle',
  setToken: (token) => set({ token }),
  setUser: (user) => set({ user, status: 'authenticated' }),
  clearSession: () => set({ token: null, user: null, status: 'unauthenticated' }),
  setStatus: (status) => set({ status }),
}));
