import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { posApi } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { can } from '../auth/access';
import { Alert, Badge, ErrorAlert, Field, Loading, Modal, PageHeader } from '../components/ui';
import { METHOD_LABELS, dateTime, rupiah, shortDate } from '../lib/format';
import { useAction, useApi } from '../lib/useApi';

export default function TransactionDetail() {
  const { id } = useParams();
  const { user } = useAuth();
  const trx = useApi(() => posApi(`/transactions/${id}`).then((r) => r.data), [id]);
  const resync = useAction();
  const [voiding, setVoiding] = useState(false);

  if (trx.loading && !trx.data) return <Loading />;
  if (!trx.data) return <ErrorAlert error={trx.error} />;
  const t = trx.data;

  const canVoid = can(user, 'voidTransaction') && t.status === 'paid' && t.shift_status === 'open';
  const canResync = can(user, 'resyncFinance') && t.status !== 'pending' && ['failed', 'pending'].includes(t.finance_sync_status);

  return (
    <>
      <PageHeader
        title={t.trx_number}
        subtitle={<Link to="/transaksi">‹ Riwayat transaksi</Link>}
        actions={
          <>
            {canResync && (
              <button className="btn" disabled={resync.busy} onClick={() => resync.run(async () => { await posApi(`/transactions/${t.id}/resync-finance`, { method: 'POST' }); trx.reload(); })}>
                {resync.busy ? 'Mengirim…' : 'Resync Finance'}
              </button>
            )}
            {canVoid && (
              <button className="btn btn-danger" onClick={() => setVoiding(true)}>
                Void
              </button>
            )}
          </>
        }
      />
      <ErrorAlert error={resync.error} />
      {t.status === 'paid' && t.shift_status === 'closed' && can(user, 'voidTransaction') && (
        <Alert type="info">Shift transaksi ini sudah ditutup sehingga tidak dapat di-void.</Alert>
      )}

      <div className="detail-grid">
        <section className="card">
          <h2>Informasi</h2>
          <dl className="kv">
            <dt>Status</dt>
            <dd><Badge kind="trx" value={t.status} /></dd>
            <dt>Outlet</dt>
            <dd>{t.outlet_code}</dd>
            <dt>Shift</dt>
            <dd>#{t.shift_id} <Badge kind="shift" value={t.shift_status} /></dd>
            <dt>Tanggal bisnis</dt>
            <dd>{shortDate(t.business_date)}</dd>
            <dt>Kasir</dt>
            <dd>User #{t.cashier_id}</dd>
            <dt>Dibuat</dt>
            <dd>{dateTime(t.created_at)}</dd>
          </dl>
        </section>

        <section className="card">
          <h2>Pembayaran</h2>
          <dl className="kv">
            <dt>Metode</dt>
            <dd>{METHOD_LABELS[t.payment_method] || '-'}</dd>
            <dt>Uang diterima</dt>
            <dd>{rupiah(t.paid_amount)}</dd>
            <dt>Kembalian</dt>
            <dd>{rupiah(t.change_amount)}</dd>
            <dt>Dibayar</dt>
            <dd>{dateTime(t.paid_at)}</dd>
            {t.status === 'void' && (
              <>
                <dt>Di-void</dt>
                <dd>{dateTime(t.voided_at)} oleh user #{t.voided_by}</dd>
                <dt>Alasan void</dt>
                <dd>{t.void_reason}</dd>
              </>
            )}
          </dl>
        </section>

        <section className="card">
          <h2>Sinkronisasi Finance</h2>
          <dl className="kv">
            <dt>Status</dt>
            <dd><Badge kind="sync" value={t.finance_sync_status} /></dd>
            <dt>Terakhir synced</dt>
            <dd>{dateTime(t.finance_synced_at)}</dd>
            <dt>Error terakhir</dt>
            <dd className={t.finance_last_error ? 'text-danger' : ''}>{t.finance_last_error || '-'}</dd>
          </dl>
        </section>
      </div>

      <section className="card">
        <h2>Item</h2>
        <table className="table">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Produk</th>
              <th className="num">Qty</th>
              <th className="num">Harga</th>
              <th className="num">Subtotal</th>
            </tr>
          </thead>
          <tbody>
            {t.items.map((i) => (
              <tr key={i.id}>
                <td>{i.sku}</td>
                <td>{i.product_name}</td>
                <td className="num">{i.quantity}</td>
                <td className="num">{rupiah(i.unit_price)}</td>
                <td className="num">{rupiah(i.subtotal)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr><td colSpan={4} className="num">Subtotal</td><td className="num">{rupiah(t.subtotal)}</td></tr>
            <tr><td colSpan={4} className="num">Diskon</td><td className="num">− {rupiah(t.discount_amount)}</td></tr>
            <tr><td colSpan={4} className="num">PPN</td><td className="num">{rupiah(t.tax_amount)}</td></tr>
            <tr className="grand"><td colSpan={4} className="num">Grand total</td><td className="num">{rupiah(t.grand_total)}</td></tr>
          </tfoot>
        </table>
      </section>

      {voiding && <VoidModal trx={t} onClose={() => setVoiding(false)} onDone={() => { setVoiding(false); trx.reload(); }} />}
    </>
  );
}

function VoidModal({ trx, onClose, onDone }) {
  const [reason, setReason] = useState('');
  const { busy, error, run } = useAction();

  const submit = (e) => {
    e.preventDefault();
    run(async () => {
      await posApi(`/transactions/${trx.id}/void`, { method: 'POST', body: { reason } });
      onDone();
    });
  };

  return (
    <Modal
      title={`Void ${trx.trx_number}`}
      onClose={onClose}
      footer={
        <>
          <button className="btn" type="button" onClick={onClose}>Batal</button>
          <button className="btn btn-danger" form="void-form" disabled={busy || reason.trim().length < 3}>
            {busy ? 'Memproses…' : 'Void Transaksi'}
          </button>
        </>
      }
    >
      <form id="void-form" onSubmit={submit}>
        <p>Total {rupiah(trx.grand_total)} akan dibatalkan dan jurnal reversal dikirim ke Finance.</p>
        <Field label="Alasan void (wajib)" error={error?.fields?.reason}>
          <textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} required autoFocus />
        </Field>
        <ErrorAlert error={error} />
      </form>
    </Modal>
  );
}
