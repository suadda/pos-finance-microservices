import { useState } from 'react';
import { Link } from 'react-router-dom';
import { financeApi } from '../api/client';
import { Badge, Empty, ErrorAlert, Loading, PageHeader, Pagination } from '../components/ui';
import { METHOD_LABELS, dateTime, rupiah, shortDate } from '../lib/format';
import { useApi } from '../lib/useApi';
import { OutletSelect, useOutlets } from '../lib/useOutlets';

export default function Postings() {
  const { outlets } = useOutlets();
  const [filters, setFilters] = useState({ outlet_id: '', date_from: '', date_to: '', payment_method: '', entry_type: '', search: '', page: 1 });
  const list = useApi(() => financeApi('/postings', { query: { ...filters, per_page: 20 } }), [JSON.stringify(filters)]);
  const set = (k, v) => setFilters((f) => ({ ...f, [k]: v, page: k === 'page' ? v : 1 }));

  return (
    <>
      <PageHeader title="Posting & Jurnal" subtitle="Posting penjualan dari POS (sale) dan pembatalan (reversal)" />
      <div className="card filters">
        <input placeholder="Cari no. transaksi…" value={filters.search} onChange={(e) => set('search', e.target.value)} />
        <OutletSelect value={filters.outlet_id} onChange={(v) => set('outlet_id', v)} outlets={outlets} />
        <select value={filters.payment_method} onChange={(e) => set('payment_method', e.target.value)}>
          <option value="">Semua metode</option>
          {Object.entries(METHOD_LABELS).map(([v, l]) => (
            <option key={v} value={v}>{l}</option>
          ))}
        </select>
        <select value={filters.entry_type} onChange={(e) => set('entry_type', e.target.value)}>
          <option value="">Sale & reversal</option>
          <option value="sale">Sale</option>
          <option value="reversal">Reversal</option>
        </select>
        <label className="inline">Dari <input type="date" value={filters.date_from} onChange={(e) => set('date_from', e.target.value)} /></label>
        <label className="inline">s/d <input type="date" value={filters.date_to} onChange={(e) => set('date_to', e.target.value)} /></label>
      </div>
      <section className="card">
        <ErrorAlert error={list.error} />
        {list.loading && !list.data ? (
          <Loading />
        ) : list.data?.data.length === 0 ? (
          <Empty />
        ) : (
          <div className="table-scroll">
            <table className="table">
              <thead>
                <tr>
                  <th>No. Transaksi</th>
                  <th>Jenis</th>
                  <th>Outlet</th>
                  <th>Tanggal bisnis</th>
                  <th>Metode</th>
                  <th className="num">Penjualan bersih</th>
                  <th className="num">PPN</th>
                  <th className="num">Total</th>
                  <th>Diposting</th>
                </tr>
              </thead>
              <tbody>
                {list.data?.data.map((p) => (
                  <tr key={p.id}>
                    <td><Link to={`/postings/${p.id}`}>{p.trx_number}</Link></td>
                    <td><Badge kind="entry" value={p.entry_type} /></td>
                    <td>{p.outlet_code}</td>
                    <td>{shortDate(p.business_date)}</td>
                    <td>{METHOD_LABELS[p.payment_method]}</td>
                    <td className="num">{rupiah(p.net_sales_amount)}</td>
                    <td className="num">{rupiah(p.tax_amount)}</td>
                    <td className="num">{rupiah(p.total_amount)}</td>
                    <td>{dateTime(p.posted_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pagination meta={list.data?.meta} onPage={(p) => set('page', p)} />
      </section>
    </>
  );
}
