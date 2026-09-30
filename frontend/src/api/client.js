// Thin fetch wrapper: bearer token, one transparent refresh+retry on 401, uniform ApiError.

const BASE = (import.meta.env.VITE_API_BASE || '/api').replace(/\/$/, '');
const STORAGE_KEY = 'posfin.tokens';

export class ApiError extends Error {
  constructor(status, code, message, details = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details || {};
  }

  /** Field-level validation messages ({ field: message }) for 422 responses. */
  get fields() {
    return this.details.fields || {};
  }
}

let tokens = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
let refreshInFlight = null;
const handlers = { onUnauthorized: () => {}, onForbidden: () => {}, onTokens: () => {} };

export function setAuthHandlers(next) {
  Object.assign(handlers, next);
}

export function getTokens() {
  return tokens;
}

export function setTokens(next) {
  tokens = next ? { access: next.access_token, refresh: next.refresh_token } : null;
  if (tokens) localStorage.setItem(STORAGE_KEY, JSON.stringify(tokens));
  else localStorage.removeItem(STORAGE_KEY);
  handlers.onTokens(tokens);
}

/** Exchanges the refresh token for a new pair (deduplicated across concurrent callers). */
export function refreshTokens() {
  if (!tokens?.refresh) return Promise.reject(new ApiError(401, 'NO_REFRESH_TOKEN', 'Sesi berakhir'));
  refreshInFlight ??= request('/auth/refresh', { method: 'POST', body: { refresh_token: tokens.refresh }, auth: false })
    .then((res) => {
      setTokens(res.data);
      return res.data;
    })
    .finally(() => {
      refreshInFlight = null;
    });
  return refreshInFlight;
}

function buildUrl(path, query) {
  const url = new URL(BASE + path, window.location.origin);
  Object.entries(query || {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
  });
  return url.toString();
}

async function request(path, { method = 'GET', body, query, auth = true } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (auth && tokens?.access) headers.Authorization = `Bearer ${tokens.access}`;

  let response;
  try {
    response = await fetch(buildUrl(path, query), {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', 'Tidak dapat terhubung ke server. Periksa koneksi Anda.');
  }

  const json = await response.json().catch(() => null);
  if (response.ok) return json;

  const err = json?.error || {};
  const message =
    response.status >= 500
      ? err.message || `Terjadi kesalahan pada server (${response.status}). Silakan coba lagi.`
      : err.message || `Permintaan gagal (${response.status})`;
  throw new ApiError(response.status, err.code || `HTTP_${response.status}`, message, err.details);
}

/**
 * Main entry point. 401 -> refresh once and retry, else session ends (redirect to login).
 * 403 -> forbidden page. 409/422/5xx -> ApiError with the API's business message.
 */
export async function api(path, options = {}) {
  try {
    return await request(path, options);
  } catch (e) {
    if (!(e instanceof ApiError)) throw e;
    const auth = options.auth !== false;

    if (e.status === 401 && auth) {
      if (!options._retried && tokens?.refresh) {
        try {
          await refreshTokens();
          return await api(path, { ...options, _retried: true });
        } catch (refreshError) {
          if (refreshError instanceof ApiError && refreshError.status !== 401) throw refreshError;
        }
      }
      setTokens(null);
      handlers.onUnauthorized();
    } else if (e.status === 403 && auth) {
      handlers.onForbidden(e);
    }
    throw e;
  }
}

export const authApi = (path, options) => api(`/auth${path}`, options);
export const posApi = (path, options) => api(`/pos${path}`, options);
export const financeApi = (path, options) => api(`/finance${path}`, options);

/** Reads the `exp` claim (seconds) of a JWT without verifying it (verification is server-side). */
export function tokenExpiry(jwt) {
  try {
    const payload = JSON.parse(atob(jwt.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
    return payload.exp || null;
  } catch {
    return null;
  }
}
