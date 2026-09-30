import { Link, useSearchParams } from 'react-router-dom';
import { posApi } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { can } from '../auth/access';
import { Badge, Empty, ErrorAlert, Loading, PageHeader, Pagination } from '../components/ui';
import { METHOD_LABELS, dateTime, rupiah, shortDate } from '../lib/format';
import { useAction, useApi } from '../lib/useApi';
import { OutletSelect, useOutlets } from '../lib/useOutlets';

const FILTERS = ['status', 'payment_method', 'finance_sync_status', 'date_from', 'date_to', 'outlet_id', 'search', 'page'];

export default function Transactions() {
  const { user } = useAuth();
  const { outlets } = useOutlets();
  const [params, setParams] = useSearchParams();
  const query = Object.fromEntries(FILTERS.map((k) => [k, params.get(k) || '']));
  const key = params.toString();
  const list = useApi(() => posApi('/transactions', { query }), [key]);
  const resync = useAction();

  const setFilter = (name, value) => {
    const next = new URLSearchParams(params);
    if (value) next.set(name, value);
    else next.delete(name);
    if (name !== 'page') next.delete('page');
    setParams(next);
  };

  const doResync = (id) => resync.run(async () => {
    await posApi(`/transactions/${id}/resync-finance`, { method: 'POST' });
    list.reload();
  });

  return (
    <>
      <PageHeader title="Riwayat Transaksi" subtitle={user.role === 'kasir' ? 'Transaksi milik Anda' : user.role === 'supervisor_pos' ? 'Semua transaksi di outlet Anda' : 'Semua outlet'} />
      <div className="card filters">
        <input placeholder="Cari no. transaksi…" value={query.search} onChange={(e) => setFilter('search', e.target.value)} />
        <select value={query.status} onChange={(e) => setFilter('status', e.target.value)}>
          <option value="">Semua status</option>
          <option value="pending">Pending</option>
          <option value="paid">Paid</option>
          <option value="void">Void</option>
        </select>
        <select value={query.payment_method} onChange={(e) => setFilter('payment_method', e.target.value)}>
          <option value="">Semua metode</option>
          {Object.entries(METHOD_LABELS).map(([v, l]) => (
            <option key={v} value={v}>{l}</option>
          ))}
        </select>
        <select value={query.finance_sync_status} onChange={(e) => setFilter('finance_sync_status', e.target.value)}>
          <option value="">Semua sync</option>
          <option value="synced">Synced</option>
          <option value="pending">Pending</option>
          <option value="failed">Failed</option>
          <option value="none">None</option>
        </select>
        {user.role === 'superadmin' && <OutletSelect value={query.outlet_id} onChange={(v) => setFilter('outlet_id', v)} outlets={outlets} />}
        <label className="inline">
          Dari <input type="date" value={query.date_from} onChange={(e) => setFilter('date_from', e.target.value)} />
        </label>
        <label className="inline">
          s/d <input type="date" value={query.date_to} onChange={(e) => setFilter('date_to', e.target.value)} />
        </label>
      </div>

      <section className="card">
        <ErrorAlert error={list.error || resync.error} />
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
                  <th>Tanggal bisnis</th>
                  <th>Status</th>
                  <th>Metode</th>
                  <th className="num">Total</th>
                  <th>Sync Finance</th>
                  <th>Dibayar</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {list.data?.data.map((t) => (
                  <tr key={t.id}>
                    <td>
                      <Link to={`/transaksi/${t.id}`}>{t.trx_number}</Link>
                    </td>
                    <td>{shortDate(t.business_date)}</td>
                    <td><Badge kind="trx" value={t.status} /></td>
                    <td>{METHOD_LABELS[t.payment_method] || '-'}</td>
                    <td className="num">{rupiah(t.grand_total)}</td>
                    <td><Badge kind="sync" value={t.finance_sync_status} /></td>
                    <td>{dateTime(t.paid_at)}</td>
                    <td>
                      {can(user, 'resyncFinance') && t.status !== 'pending' && ['failed', 'pending'].includes(t.finance_sync_status) && (
                        <button className="btn btn-sm" disabled={resync.busy} onClick={() => doResync(t.id)}>
                          Resync Finance
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pagination meta={list.data?.meta} onPage={(p) => setFilter('page', String(p))} />
      </section>
    </>
  );
}
