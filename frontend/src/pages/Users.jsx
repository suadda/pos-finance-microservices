import { useState } from 'react';
import { authApi } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { ROLES, ROLE_LABELS } from '../auth/access';
import { Badge, Empty, ErrorAlert, Field, Loading, Modal, PageHeader, Pagination } from '../components/ui';
import { useAction, useApi } from '../lib/useApi';
import { OutletSelect, useOutlets } from '../lib/useOutlets';

const OUTLET_ROLES = ['kasir', 'supervisor_pos'];

export default function Users() {
  const { user: me } = useAuth();
  const { outlets, nameOf } = useOutlets();
  const [filters, setFilters] = useState({ search: '', role: '', is_active: '', page: 1 });
  const list = useApi(() => authApi('/users', { query: { ...filters, per_page: 20 } }), [JSON.stringify(filters)]);
  const [editing, setEditing] = useState(null);
  const toggle = useAction();
  const set = (k, v) => setFilters((f) => ({ ...f, [k]: v, page: k === 'page' ? v : 1 }));

  const setActive = (u, active) =>
    toggle.run(async () => {
      await authApi(active ? `/users/${u.id}/activate` : `/users/${u.id}`, { method: active ? 'POST' : 'DELETE' });
      list.reload();
    });

  return (
    <>
      <PageHeader title="Manajemen User" actions={<button className="btn btn-primary" onClick={() => setEditing({})}>+ Tambah User</button>} />
      <div className="card filters">
        <input placeholder="Cari nama / email…" value={filters.search} onChange={(e) => set('search', e.target.value)} />
        <select value={filters.role} onChange={(e) => set('role', e.target.value)}>
          <option value="">Semua role</option>
          {ROLES.map((r) => <option key={r} value={r}>{ROLE_LABELS[r]}</option>)}
        </select>
        <select value={filters.is_active} onChange={(e) => set('is_active', e.target.value)}>
          <option value="">Semua status</option>
          <option value="1">Aktif</option>
          <option value="0">Nonaktif</option>
        </select>
      </div>
      <section className="card">
        <ErrorAlert error={list.error || toggle.error} />
        {list.loading && !list.data ? (
          <Loading />
        ) : list.data?.data.length === 0 ? (
          <Empty />
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th>Nama</th>
                <th>Email</th>
                <th>Role</th>
                <th>Outlet</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {list.data?.data.map((u) => (
                <tr key={u.id}>
                  <td>{u.name}</td>
                  <td>{u.email}</td>
                  <td>{ROLE_LABELS[u.role]}</td>
                  <td>{u.outlet_id ? nameOf(u.outlet_id) : <span className="muted">Semua outlet</span>}</td>
                  <td><Badge kind="active" value={u.is_active} label={u.is_active ? 'Aktif' : 'Nonaktif'} /></td>
                  <td className="actions">
                    <button className="btn btn-sm" onClick={() => setEditing(u)}>Edit</button>
                    {u.id !== me.id &&
                      (u.is_active ? (
                        <button className="btn btn-sm btn-danger-outline" disabled={toggle.busy} onClick={() => setActive(u, false)}>Nonaktifkan</button>
                      ) : (
                        <button className="btn btn-sm" disabled={toggle.busy} onClick={() => setActive(u, true)}>Aktifkan</button>
                      ))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination meta={list.data?.meta} onPage={(p) => set('page', p)} />
      </section>
      {editing && <UserForm user={editing} outlets={outlets} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); list.reload(); }} />}
    </>
  );
}

function UserForm({ user, outlets, onClose, onSaved }) {
  const isNew = !user.id;
  const [form, setForm] = useState({
    name: user.name || '',
    email: user.email || '',
    password: '',
    role: user.role || 'kasir',
    outlet_id: user.outlet_id || '',
    is_active: user.is_active ?? true,
  });
  const { busy, error, run } = useAction();
  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  const needsOutlet = OUTLET_ROLES.includes(form.role);
  const financeRole = ['staff_finance', 'manager_finance'].includes(form.role);

  const submit = (e) => {
    e.preventDefault();
    const body = { ...form, outlet_id: financeRole || !form.outlet_id ? null : Number(form.outlet_id) };
    if (!isNew && !body.password) delete body.password;
    run(async () => {
      await authApi(isNew ? '/users' : `/users/${user.id}`, { method: isNew ? 'POST' : 'PUT', body });
      onSaved();
    });
  };

  return (
    <Modal
      title={isNew ? 'Tambah User' : `Edit ${user.name}`}
      onClose={onClose}
      footer={
        <>
          <button className="btn" type="button" onClick={onClose}>Batal</button>
          <button className="btn btn-primary" form="user-form" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan'}</button>
        </>
      }
    >
      <form id="user-form" onSubmit={submit}>
        <Field label="Nama" error={error?.fields?.name}>
          <input value={form.name} onChange={(e) => set('name', e.target.value)} required maxLength={150} />
        </Field>
        <Field label="Email" error={error?.fields?.email}>
          <input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} required maxLength={150} />
        </Field>
        <Field label={isNew ? 'Password' : 'Password baru (kosongkan jika tidak diubah)'} error={error?.fields?.password}>
          <input type="password" value={form.password} onChange={(e) => set('password', e.target.value)} required={isNew} minLength={8} />
        </Field>
        <Field label="Role" error={error?.fields?.role}>
          <select value={form.role} onChange={(e) => set('role', e.target.value)}>
            {ROLES.map((r) => <option key={r} value={r}>{ROLE_LABELS[r]}</option>)}
          </select>
        </Field>
        {!financeRole && (
          <Field label={needsOutlet ? 'Outlet (wajib)' : 'Outlet (opsional)'} error={error?.fields?.outlet_id}>
            <OutletSelect value={form.outlet_id} onChange={(v) => set('outlet_id', v)} outlets={outlets} allowAll={!needsOutlet} required={needsOutlet} />
          </Field>
        )}
        {financeRole && <p className="muted small">Role finance mengakses semua outlet (outlet_id kosong).</p>}
        <label className="checkbox">
          <input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} /> Aktif
        </label>
        <ErrorAlert error={error} />
      </form>
    </Modal>
  );
}
