import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext';
import { can } from './access';

/** Route guard: unauthenticated -> /login, missing permission -> /forbidden. */
export default function RequireAuth({ permission }) {
  const { user, ready } = useAuth();
  const location = useLocation();

  if (!ready) return <div className="page-loading">Memuat sesi…</div>;
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  if (permission && !can(user, permission)) return <Navigate to="/forbidden" replace />;
  return <Outlet />;
}
