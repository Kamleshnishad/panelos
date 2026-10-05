import { SessionUser } from './session';

// Permission keys match backend/config/permissions.php exactly — not guessed.
// Admins get effectivePermissions() === ['*'] server-side (backend/app/Models/User.php).
export function hasPermission(user: SessionUser | null, key: string): boolean {
  if (!user) return false;
  if (user.is_admin) return true;
  return user.permissions?.includes('*') || user.permissions?.includes(key);
}

export function hasAnyPermission(user: SessionUser | null, keys: string[]): boolean {
  return keys.some((key) => hasPermission(user, key));
}
