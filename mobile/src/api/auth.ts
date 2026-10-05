import { apiClient } from './client';
import { SessionUser } from '../auth/session';

// Response shapes confirmed against backend/app/Http/Controllers/Api/AuthController.php
// (login/issueLogin and me), not the older API docs.

type ApiEnvelope<T> = { success: boolean; message: string; data: T };

type LoginData =
  | { needs_2fa: true; email: string }
  | { user: { id: number; name: string; email: string; company_id: number; is_super_admin: boolean; is_company_admin: boolean }; token: string; token_type: 'Bearer' };

export async function login(email: string, password: string): Promise<ApiEnvelope<LoginData>> {
  const res = await apiClient.post<ApiEnvelope<LoginData>>('/auth/login', { email, password });
  return res.data;
}

export async function me(): Promise<ApiEnvelope<SessionUser>> {
  const res = await apiClient.get<ApiEnvelope<SessionUser>>('/auth/me');
  return res.data;
}

export async function logout(): Promise<void> {
  await apiClient.post('/auth/logout');
}
