import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { financeApi } from '../api/client';
import { Badge, Empty, ErrorAlert, Field, Loading, PageHeader, Pagination } from '../components/ui';
import { dateTime, rupiah, shortDate, todayJakarta } from '../lib/format';
import { useAction, useApi } from '../lib/useApi';
import { OutletSelect, useOutlets } from '../lib/useOutlets';

export default function Reconciliations() {
  const { outlets, nameOf } = useOutlets();
  const navigate = useNavigate();
  const [runForm, setRunForm] = useState({ outlet_id: '', business_date: todayJakarta() });
  const [filters, setFilters] = useState({ outlet_id: '', status: '', date_from: '', date_to: '', page: 1 });
  const list = useApi(() => financeApi('/reconciliations', { query: { ...filters, per_page: 20 } }), [JSON.stringify(filters)]);
  const runner = useAction();
  const set = (k, v) => setFilters((f) => ({ ...f, [k]: v, page: k === 'page' ? v : 1 }));

  const run = (e) => {
    e.preventDefault();
    runner.run(async () => {
      const res = await financeApi('/reconciliations/run', {
        method: 'POST',
        body: { outlet_id: Number(runForm.outlet_id), business_date: runForm.business_date },
      });
      navigate(`/rekonsiliasi/${res.data.id}`);
    });
  };

  return (
    <>
      <PageHeader title="Rekonsiliasi End of Day" subtitle="Mencocokkan ringkasan POS dengan ledger Finance per outlet per hari" />

      <form className="card run-form" onSubmit={run}>
        <Field label="Outlet">
          <OutletSelect value={runForm.outlet_id} onChange={(v) => setRunForm((f) => ({ ...f, outlet_id: v }))} outlets={outlets} allowAll={false} required />
        </Field>
        <Field label="Tanggal bisnis">
          <input type="date" max={todayJakarta()} value={runForm.business_date} onChange={(e) => setRunForm((f) => ({ ...f, business_date: e.target.value }))} required />
        </Field>
        <button className="btn btn-primary" disabled={runner.busy || !runForm.outlet_id}>
          {runner.busy ? 'Menjalankan…' : 'Jalankan Rekonsiliasi'}
        </button>
      </form>
      <ErrorAlert error={runner.error} />

      <div className="card filters">
        <OutletSelect value={filters.outlet_id} onChange={(v) => set('outlet_id', v)} outlets={outlets} />
        <select value={filters.status} onChange={(e) => set('status', e.target.value)}>
          <option value="">Semua status</option>
          <option value="pending">Pending</option>
          <option value="matched">Matched</option>
          <option value="mismatch">Mismatch</option>
          <option value="resolved">Resolved</option>
        </select>
        <label className="inline">Dari <input type="date" value={filters.date_from} onChange={(e) => set('date_from', e.target.value)} /></label>
        <label className="inline">s/d <input type="date" value={filters.date_to} onChange={(e) => set('date_to', e.target.value)} /></label>
      </div>

      <section className="card">
        <ErrorAlert error={list.error} />
        {list.loading && !list.data ? (
          <Loading />
        ) : list.data?.data.length === 0 ? (
          <Empty>Belum ada rekonsiliasi</Empty>
        ) : (
          <table className="table">
            <thead>
              <tr>
                <th>Tanggal bisnis</th>
                <th>Outlet</th>
                <th>Status</th>
                <th className="num">POS total</th>
                <th className="num">Finance total</th>
                <th className="num">Selisih kas</th>
                <th>Dijalankan</th>
              </tr>
            </thead>
            <tbody>
              {list.data?.data.map((r) => {
                const pos = Number(r.pos_cash) + Number(r.pos_debit) + Number(r.pos_qris);
                const fin = Number(r.fin_cash) + Number(r.fin_debit) + Number(r.fin_qris);
                return (
                  <tr key={r.id}>
                    <td><Link to={`/rekonsiliasi/${r.id}`}>{shortDate(r.business_date)}</Link></td>
                    <td>{nameOf(r.outlet_id)}</td>
                    <td><Badge kind="recon" value={r.status} /></td>
                    <td className="num">{rupiah(pos)}</td>
                    <td className={`num ${pos !== fin ? 'text-danger' : ''}`}>{rupiah(fin)}</td>
                    <td className={`num ${Number(r.cash_variance) ? 'text-danger' : ''}`}>{rupiah(r.cash_variance)}</td>
                    <td>{dateTime(r.run_at)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
        <Pagination meta={list.data?.meta} onPage={(p) => set('page', p)} />
      </section>
    </>
  );
}
