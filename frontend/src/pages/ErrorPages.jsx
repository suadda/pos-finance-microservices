import { Link } from 'react-router-dom';

export function Forbidden() {
  return (
    <div className="error-page">
      <div className="error-code">403</div>
      <h2>Akses ditolak</h2>
      <p className="muted">Role Anda tidak memiliki akses ke halaman atau aksi ini.</p>
      <Link className="btn" to="/">
        Kembali ke Dashboard
      </Link>
    </div>
  );
}

export function NotFound() {
  return (
    <div className="error-page">
      <div className="error-code">404</div>
      <h2>Halaman tidak ditemukan</h2>
      <Link className="btn" to="/">
        Kembali ke Dashboard
      </Link>
    </div>
  );
}
