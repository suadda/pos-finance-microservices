// Small shared UI building blocks.
import { useEffect } from 'react';

const BADGE_COLORS = {
  trx: { pending: 'gray', paid: 'green', void: 'dark' },
  sync: { synced: 'green', pending: 'yellow', failed: 'red', none: 'gray' },
  recon: { pending: 'gray', matched: 'green', mismatch: 'red', resolved: 'blue' },
  shift: { open: 'green', closed: 'gray' },
  entry: { sale: 'green', reversal: 'red' },
  active: { true: 'green', false: 'gray' },
};

export function Badge({ kind, value, label }) {
  const color = BADGE_COLORS[kind]?.[String(value)] || 'gray';
  return <span className={`badge badge-${color}`}>{label ?? String(value ?? '-')}</span>;
}

/** Renders ApiError messages: business message for 409/422 (+ field errors), generic for 5xx/network. */
export function ErrorAlert({ error }) {
  if (!error) return null;
  const fields = Object.values(error.fields || {});
  return (
    <div className="alert alert-error" role="alert">
      <strong>{error.message || 'Terjadi kesalahan'}</strong>
      {fields.length > 0 && (
        <ul>
          {fields.map((m) => (
            <li key={m}>{m}</li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function Alert({ type = 'info', children }) {
  return <div className={`alert alert-${type}`}>{children}</div>;
}

export function Pagination({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return meta ? <div className="pagination muted">{meta.total} data</div> : null;
  return (
    <div className="pagination">
      <button className="btn btn-sm" disabled={meta.page <= 1} onClick={() => onPage(meta.page - 1)}>
        ‹ Sebelumnya
      </button>
      <span>
        Halaman {meta.page} / {meta.last_page} · {meta.total} data
      </span>
      <button className="btn btn-sm" disabled={meta.page >= meta.last_page} onClick={() => onPage(meta.page + 1)}>
        Berikutnya ›
      </button>
    </div>
  );
}

export function Modal({ title, onClose, children, footer }) {
  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div className="modal-backdrop" onMouseDown={onClose}>
      <div className="modal" role="dialog" aria-modal="true" onMouseDown={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <h3>{title}</h3>
          <button className="icon-btn" onClick={onClose} aria-label="Tutup">
            ×
          </button>
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-footer">{footer}</div>}
      </div>
    </div>
  );
}

export function Field({ label, error, children, hint }) {
  return (
    <label className="field">
      <span className="field-label">{label}</span>
      {children}
      {hint && <span className="field-hint">{hint}</span>}
      {error && <span className="field-error">{error}</span>}
    </label>
  );
}

export function Loading({ text = 'Memuat…' }) {
  return <div className="loading">{text}</div>;
}

export function Empty({ children = 'Tidak ada data' }) {
  return <div className="empty">{children}</div>;
}

export function PageHeader({ title, subtitle, actions }) {
  return (
    <div className="page-header">
      <div>
        <h1>{title}</h1>
        {subtitle && <p className="muted">{subtitle}</p>}
      </div>
      {actions && <div className="page-actions">{actions}</div>}
    </div>
  );
}

export function Stat({ label, value, tone }) {
  return (
    <div className={`stat ${tone ? `stat-${tone}` : ''}`}>
      <span className="stat-label">{label}</span>
      <span className="stat-value">{value}</span>
    </div>
  );
}
