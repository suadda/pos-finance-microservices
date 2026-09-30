import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { financeApi } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { can } from '../auth/access';
import { Alert, Badge, ErrorAlert, Field, Loading, Modal, PageHeader } from '../components/ui';
import { METHOD_LABELS, dateTime, isNonZero, rupiah, shortDate } from '../lib/format';
import { useAction, useApi } from '../lib/useApi';

const REASON_TEXT = {
  AMOUNT_DIFF: (r) => `Selisih nominal ${METHOD_LABELS[r.method]}: POS ${rupiah(r.pos)} vs Finance ${rupiah(r.finance)} (selisih ${rupiah(r.diff)})`,
  COUNT_DIFF: (r) => `Selisih jumlah transaksi: POS ${r.pos} vs Finance ${r.finance} (selisih ${r.diff})`,
  CASH_VARIANCE: (r) => `Selisih kas fisik seluruh shift: ${rupiah(r.amount)}`,
  UNSYNCED_TRANSACTIONS: (r) => `${r.count} transaksi POS belum tersinkron ke Finance — ${r.message}`,
};

export default function ReconciliationDetail() {
  const { id } = useParams();
  const { user } = useAuth();
  const recon = useApi(() => financeApi(`/reconciliations/${id}`).then((r) => r.data), [id]);
  const rerun = useAction();
  const [resolving, setResolving] = useState(false);

  if (recon.loading && !recon.data) return <Loading />;
  if (!recon.data) return <ErrorAlert error={recon.error} />;
  const r = recon.data;
  const c = r.comparison;

  const runAgain = () =>
    rerun.run(async () => {
      await financeApi('/reconciliations/run', { method: 'POST', body: { outlet_id: r.outlet_id, business_date: r.business_date } });
      recon.reload();
    });

  return (
    <>
      <PageHeader
        title={`EOD ${r.outlet_code} · ${shortDate(r.business_date)}`}
        subtitle={<Link to="/rekonsiliasi">‹ Daftar rekonsiliasi</Link>}
        actions={
          <>
            <Badge kind="recon" value={r.status} />
            {r.status === 'mismatch' && (
              <button className="btn" disabled={rerun.busy} onClick={runAgain}>
                {rerun.busy ? 'Menjalankan…' : 'Jalankan Ulang'}
              </button>
            )}
            {r.status === 'mismatch' && can(user, 'resolveMismatch') && (
              <button className="btn btn-primary" onClick={() => setResolving(true)}>Resolve</button>
            )}
          </>
        }
      />
      <ErrorAlert error={rerun.error} />

      <section className="card">
        <h2>POS vs Finance</h2>
        <table className="table comparison">
          <thead>
            <tr>
              <th />
              <th className="num">POS</th>
              <th className="num">Finance (ledger)</th>
              <th className="num">Selisih (POS − Finance)</th>
            </tr>
          </thead>
          <tbody>
            {['cash', 'debit', 'qris'].map((m) => (
              <tr key={m}>
                <td>{METHOD_LABELS[m]}</td>
                <td className="num">{rupiah(c[m].pos)}</td>
                <td className="num">{rupiah(c[m].finance)}</td>
                <td className={`num ${isNonZero(c[m].diff) ? 'diff-bad' : 'diff-ok'}`}>{rupiah(c[m].diff)}</td>
              </tr>
            ))}
            <tr>
              <td>Jumlah transaksi</td>
              <td className="num">{c.trx_count.pos}</td>
              <td className="num">{c.trx_count.finance}</td>
              <td className={`num ${c.trx_count.diff !== 0 ? 'diff-bad' : 'diff-ok'}`}>{c.trx_count.diff}</td>
            </tr>
            <tr>
              <td>Selisih kas fisik (cash variance)</td>
              <td className={`num ${isNonZero(r.cash_variance) ? 'diff-bad' : 'diff-ok'}`}>{rupiah(r.cash_variance)}</td>
              <td className="num muted">-</td>
              <td />
            </tr>
          </tbody>
        </table>
        <p className="muted small">
          Void di POS: {r.pos_void_count} transaksi · Transaksi belum synced: {r.pos_unsynced_count}
        </p>
      </section>

      {r.mismatch_reasons?.length > 0 && (
        <section className="card">
          <h2>Penyebab mismatch</h2>
          <ul className="reasons">
            {r.mismatch_reasons.map((reason, i) => (
              <li key={i}>
                <code>{reason.type}</code> {REASON_TEXT[reason.type]?.(reason) || JSON.stringify(reason)}
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="card">
        <dl className="kv kv-inline">
          <dt>Terakhir dijalankan</dt>
          <dd>{dateTime(r.run_at)} oleh user #{r.run_by}</dd>
          {r.status === 'resolved' && (
            <>
              <dt>Diselesaikan</dt>
              <dd>{dateTime(r.resolved_at)} oleh user #{r.resolved_by}</dd>
              <dt>Catatan</dt>
              <dd>{r.resolution_note}</dd>
            </>
          )}
        </dl>
        {r.status === 'resolved' && <Alert type="info">Rekonsiliasi ini sudah final dan tidak dapat dijalankan ulang.</Alert>}
      </section>

      {resolving && <ResolveModal recon={r} onClose={() => setResolving(false)} onDone={() => { setResolving(false); recon.reload(); }} />}
    </>
  );
}

function ResolveModal({ recon, onClose, onDone }) {
  const [note, setNote] = useState('');
  const { busy, error, run } = useAction();

  const submit = (e) => {
    e.preventDefault();
    run(async () => {
      await financeApi(`/reconciliations/${recon.id}/resolve`, { method: 'PATCH', body: { resolution_note: note } });
      onDone();
    });
  };

  return (
    <Modal
      title="Resolve mismatch"
      onClose={onClose}
      footer={
        <>
          <button className="btn" type="button" onClick={onClose}>Batal</button>
          <button className="btn btn-primary" form="resolve-form" disabled={busy}>{busy ? 'Menyimpan…' : 'Resolve'}</button>
        </>
      }
    >
      <form id="resolve-form" onSubmit={submit}>
        <p>Setelah di-resolve, rekonsiliasi {recon.outlet_code} {shortDate(recon.business_date)} bersifat final.</p>
        <Field label="Catatan penyelesaian (wajib)" error={error?.fields?.resolution_note}>
          <textarea rows={4} value={note} onChange={(e) => setNote(e.target.value)} autoFocus />
        </Field>
        <ErrorAlert error={error} />
      </form>
    </Modal>
  );
}
