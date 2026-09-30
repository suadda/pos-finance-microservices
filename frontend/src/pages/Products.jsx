import { useState } from 'react';
import { posApi } from '../api/client';
import { Badge, Empty, ErrorAlert, Field, Loading, Modal, PageHeader, Pagination } from '../components/ui';
import { rupiah } from '../lib/format';
import { useAction, useApi } from '../lib/useApi';

export default function Products() {
  const [filters, setFilters] = useState({ search: '', is_active: '', page: 1 });
  const list = useApi(() => posApi('/products', { query: { ...filters, per_page: 20 } }), [JSON.stringify(filters)]);
  const [editing, setEditing] = useState(null); // {} = new, product = edit
  const toggle = useAction();

  const set = (k, v) => setFilters((f) => ({ ...f, [k]: v, page: k === 'page' ? v : 1 }));

  const setActive = (p, active) =>
    toggle.run(async () => {
      if (active) await posApi(`/products/${p.id}`, { method: 'PUT', body: { is_active: true } });
      else await posApi(`/products/${p.id}`, { method: 'DELETE' });
      list.reload();
    });

  return (
    <>
      <PageHeader
        title="Manajemen Produk"
        actions={
          <button className="btn btn-primary" onClick={() => setEditing({})}>
            + Tambah Produk
          </button>
        }
      />
      <div className="card filters">
        <input placeholder="Cari nama / SKU…" value={filters.search} onChange={(e) => set('search', e.target.value)} />
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
                <th>SKU</th>
                <th>Nama</th>
                <th className="num">Harga</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {list.data?.data.map((p) => (
                <tr key={p.id}>
                  <td>{p.sku}</td>
                  <td>{p.name}</td>
                  <td className="num">{rupiah(p.price)}</td>
                  <td><Badge kind="active" value={p.is_active} label={p.is_active ? 'Aktif' : 'Nonaktif'} /></td>
                  <td className="actions">
                    <button className="btn btn-sm" onClick={() => setEditing(p)}>Edit</button>
                    {p.is_active ? (
                      <button className="btn btn-sm btn-danger-outline" disabled={toggle.busy} onClick={() => setActive(p, false)}>Nonaktifkan</button>
                    ) : (
                      <button className="btn btn-sm" disabled={toggle.busy} onClick={() => setActive(p, true)}>Aktifkan</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination meta={list.data?.meta} onPage={(p) => set('page', p)} />
      </section>
      {editing && <ProductForm product={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); list.reload(); }} />}
    </>
  );
}

function ProductForm({ product, onClose, onSaved }) {
  const isNew = !product.id;
  const [form, setForm] = useState({ sku: product.sku || '', name: product.name || '', price: product.price || '', is_active: product.is_active ?? true });
  const { busy, error, run } = useAction();
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

  const submit = (e) => {
    e.preventDefault();
    run(async () => {
      await posApi(isNew ? '/products' : `/products/${product.id}`, { method: isNew ? 'POST' : 'PUT', body: form });
      onSaved();
    });
  };

  return (
    <Modal
      title={isNew ? 'Tambah Produk' : `Edit ${product.name}`}
      onClose={onClose}
      footer={
        <>
          <button className="btn" type="button" onClick={onClose}>Batal</button>
          <button className="btn btn-primary" form="product-form" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan'}</button>
        </>
      }
    >
      <form id="product-form" onSubmit={submit}>
        <Field label="SKU" error={error?.fields?.sku}>
          <input value={form.sku} onChange={set('sku')} required maxLength={50} />
        </Field>
        <Field label="Nama produk" error={error?.fields?.name}>
          <input value={form.name} onChange={set('name')} required maxLength={200} />
        </Field>
        <Field label="Harga jual" error={error?.fields?.price}>
          <input type="number" min="0" step="0.01" value={form.price} onChange={set('price')} required />
        </Field>
        <label className="checkbox">
          <input type="checkbox" checked={form.is_active} onChange={set('is_active')} /> Aktif (dapat dijual)
        </label>
        <ErrorAlert error={error} />
      </form>
    </Modal>
  );
}
