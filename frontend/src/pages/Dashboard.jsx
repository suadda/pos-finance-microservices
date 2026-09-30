import { Link } from 'react-router-dom';
import { financeApi, posApi } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { can } from '../auth/access';
import { Badge, ErrorAlert, Loading, PageHeader, Stat } from '../components/ui';
import { METHOD_LABELS, rupiah, shortDate, todayJakarta } from '../lib/format';
import { useApi } from '../lib/useApi';
import { useOutlets } from '../lib/useOutlets';

export default function Dashboard() {
  const { user } = useAuth();
  return (
    <>
      <PageHeader title={`Halo, ${user.name}`} subtitle={`Tanggal bisnis ${shortDate(todayJakarta())} (Asia/Jakarta)`} />
      {can(user, 'posDashboard') && <PosDashboard />}
      {can(user, 'finance') && <FinanceDashboard />}
    </>
  );
}

function PosDashboard() {
  const { data, error, loading } = useApi(() => posApi('/dashboard').then((r) => r.data));

  return (
    <section className="card">
      <h2>Penjualan POS hari ini</h2>
      <ErrorAlert error={error} />
      {loading && !data ? (
        <Loading />
      ) : (
        data && (
          <>
            <div className="stats">
              <Stat label="Total penjualan (bersih)" value={rupiah(data.total_sales)} />
              <Stat label="Jumlah transaksi" value={data.trx_count} />
              <Stat label="Transaksi void" value={data.void_count} />
              <Stat
                label="Belum synced ke Finance"
                value={
                  data.unsynced_count > 0 ? <Link to="/transaksi?finance_sync_status=failed">{data.unsynced_count}</Link> : 0
                }
                tone={data.unsynced_count > 0 ? 'danger' : undefined}
              />
            </div>
            <table className="table compact">
              <thead>
                <tr>
                  <th>Metode bayar</th>
                  <th className="num">Transaksi</th>
                  <th className="num">Total</th>
                </tr>
              </thead>
              <tbody>
                {Object.entries(data.by_method).map(([method, row]) => (
                  <tr key={method}>
                    <td>{METHOD_LABELS[method]}</td>
                    <td className="num">{row.count}</td>
                    <td className="num">{rupiah(row.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )
      )}
    </section>
  );
}

function FinanceDashboard() {
  const { outlets } = useOutlets();
  const days = Array.from({ length: 7 }, (_, i) => todayJakarta(i - 6));
  const recon = useApi(() =>
    financeApi('/reconciliations', { query: { date_from: days[0], date_to: days[6], per_page: 100 } }).then((r) => r.data),
  );
  const sales = useApi(() => financeApi('/reports/daily-sales', { query: { business_date: days[6] } }).then((r) => r.data));

  const cell = (outletId, date) => recon.data?.find((r) => r.outlet_id === outletId && r.business_date === date);

  return (
    <>
      <section className="card">
        <h2>Penjualan menurut ledger Finance hari ini (semua outlet)</h2>
        <ErrorAlert error={sales.error} />
        {sales.data && (
          <div className="stats">
            <Stat label="Total bersih" value={rupiah(sales.data.total)} />
            <Stat label="Jumlah transaksi" value={sales.data.trx_count} />
            {Object.entries(sales.data.totals).map(([m, v]) => (
              <Stat key={m} label={METHOD_LABELS[m]} value={rupiah(v)} />
            ))}
          </div>
        )}
      </section>

      <section className="card">
        <h2>Status rekonsiliasi 7 hari terakhir</h2>
        <ErrorAlert error={recon.error} />
        {recon.loading && !recon.data ? (
          <Loading />
        ) : (
          <div className="table-scroll">
            <table className="table compact recon-grid">
              <thead>
                <tr>
                  <th>Outlet</th>
                  {days.map((d) => (
                    <th key={d}>{shortDate(d)}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {outlets.map((o) => (
                  <tr key={o.id}>
                    <td>
                      <strong>{o.code}</strong> <span className="muted small">{o.name}</span>
                    </td>
                    {days.map((d) => {
                      const r = cell(o.id, d);
                      return (
                        <td key={d}>
                          {r ? (
                            <Link to={`/rekonsiliasi/${r.id}`}>
                              <Badge kind="recon" value={r.status} />
                            </Link>
                          ) : (
                            <span className="muted small">belum</span>
                          )}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  );
}
