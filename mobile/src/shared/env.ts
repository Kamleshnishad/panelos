// Local dev default. The real backend mounts routes at /api directly — NOT /api/v1
// (mobile_plan/08_API_INTEGRATION_MAP.md and the older 03_API_ENDPOINTS.md doc both
// describe a /v1 prefix that doesn't exist in backend/routes/api.php; corrected here).
//
// Web preview / iOS simulator: http://localhost/api works as-is.
// Android emulator: use http://10.0.2.2/api instead of localhost.
// Physical device (Expo Go): use your dev machine's LAN IP, e.g. http://192.168.1.23/api.
// Override via EXPO_PUBLIC_API_URL in mobile/.env for any of the above.
const DEV_FALLBACK = 'http://localhost/api';

export const API_BASE_URL = process.env.EXPO_PUBLIC_API_URL ?? DEV_FALLBACK;
