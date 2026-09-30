import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { authApi, getTokens, refreshTokens, setAuthHandlers, setTokens, tokenExpiry } from '../api/client';

const AuthContext = createContext(null);
const LEEWAY = Number(import.meta.env.VITE_REFRESH_LEEWAY || 60);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [ready, setReady] = useState(false);
  const [tokens, setTokenState] = useState(getTokens());
  const navigate = useNavigate();
  const location = useLocation();
  const locationRef = useRef(location);
  locationRef.current = location;

  // Global HTTP handlers: 401 -> login page, 403 -> forbidden page.
  useEffect(() => {
    setAuthHandlers({
      onTokens: setTokenState,
      onUnauthorized: () => {
        setUser(null);
        if (locationRef.current.pathname !== '/login') {
          navigate('/login', { replace: true, state: { from: locationRef.current.pathname, expired: true } });
        }
      },
      onForbidden: () => navigate('/forbidden', { replace: true }),
    });
  }, [navigate]);

  // Restore the session on page load.
  useEffect(() => {
    if (!getTokens()) {
      setReady(true);
      return;
    }
    authApi('/me')
      .then((res) => setUser(res.data))
      .catch(() => setUser(null))
      .finally(() => setReady(true));
  }, []);

  // Refresh the access token shortly before it expires.
  useEffect(() => {
    if (!tokens?.access) return undefined;
    const exp = tokenExpiry(tokens.access);
    if (!exp) return undefined;
    const delay = Math.max(5, exp - Date.now() / 1000 - LEEWAY) * 1000;
    const timer = setTimeout(() => {
      refreshTokens()
        .then((data) => setUser(data.user))
        .catch(() => {
          setTokens(null);
          setUser(null);
          navigate('/login', { replace: true, state: { expired: true } });
        });
    }, delay);
    return () => clearTimeout(timer);
  }, [tokens, navigate]);

  const login = useCallback(async (email, password) => {
    const res = await authApi('/login', { method: 'POST', body: { email, password }, auth: false });
    setTokens(res.data);
    setUser(res.data.user);
    return res.data.user;
  }, []);

  const logout = useCallback(async () => {
    const current = getTokens();
    if (current?.refresh) {
      await authApi('/logout', { method: 'POST', body: { refresh_token: current.refresh }, auth: false }).catch(() => {});
    }
    setTokens(null);
    setUser(null);
    navigate('/login', { replace: true });
  }, [navigate]);

  const value = useMemo(() => ({ user, ready, login, logout }), [user, ready, login, logout]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  return useContext(AuthContext);
}
