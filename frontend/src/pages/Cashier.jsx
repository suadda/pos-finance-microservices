import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { posApi } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { Alert, Badge, Empty, ErrorAlert, Field, Loading, Modal, PageHeader, Stat } from '../components/ui';
import { METHOD_LABELS, dateTime, isNonZero, rupiah, shortDate } from '../lib/format';
import { TAX_RATE, previewTotals, subtractMoney } from '../lib/money';
import { useAction, useApi } from '../lib/useApi';
import { OutletSelect, useOutlets } from '../lib/useOutlets';

/** Layar Kasir: open shift -> sell -> pay -> close shift. */
export default function Cashier() {
  const current = useApi(() => posApi('/shifts/current').then((r) => r.data));
  const [closedResult, setClosedResult] = useState(null);

  if (current.loading && !current.data) return <Loading />;

  return (
    <>
      <PageHeader title="Layar Kasir" />
      <ErrorAlert error={current.error} />
      {closedResult && <ClosedShiftNotice result={closedResult} onDismiss={() => setClosedResult(null)} />}
      {current.data ? (
        <SalesScreen summary={current.data} reloadSummary={current.reload} onClosed={(r) => { setClosedResult(r); current.reload(); }} />
      ) : (
        !current.error && <OpenShiftForm onOpened={current.reload} />
      )}
    </>
  );
}

function OpenShiftForm({ onOpened }) {
  const { user } = useAuth();
  const { outlets } = useOutlets();
  const [openingCash, setOpeningCash] = useState('');
  const [outletId, setOutletId] = useState('');
  const { busy, error, run } = useAction();

  const submit = (e) => {
    e.preventDefault();
    run(async () => {
      await posApi('/shifts/open', {
        method: 'POST',
        body: { opening_cash: openingCash, outlet_id: user.outlet_id ? undefined : Number(outletId) || undefined },
      });
      onOpened();
    });
  };

  return (
    <form className="card narrow" onSubmit={submit}>
      <h2>Buka Shift</h2>
      <p className="muted">Belum ada shift terbuka. Masukkan modal kas awal untuk mulai berjualan.</p>
      <ErrorAlert error={error} />
      {!user.outlet_id && (
        <Field label="Outlet" hint="Akun Anda tidak terikat outlet, pilih outlet shift ini.">
          <OutletSelect value={outletId} onChange={setOutletId} outlets={outlets} allowAll={false} required />
        </Field>
      )}
      <Field label="Modal kas awal (opening cash)" error={error?.fields?.opening_cash}>
        <input type="number" min="0" step="0.01" value={openingCash} onChange={(e) => setOpeningCash(e.target.value)} required autoFocus />
      </Field>
      <button className="btn btn-primary" disabled={busy}>
        {busy ? 'Membuka…' : 'Buka Shift'}
      </button>
    </form>
  );
}

function SalesScreen({ summary, reloadSummary, onClosed }) {
  const shift = summary.shift;
  const [cart, setCart] = useState([]);
  const [discount, setDiscount] = useState('');
  const [paying, setPaying] = useState(null); // server transaction being paid
  const [lastPaid, setLastPaid] = useState(null);
  const [closing, setClosing] = useState(false);
  const trxList = useApi(() => posApi('/transactions', { query: { shift_id: shift.id, per_page: 100 } }).then((r) => r.data), [shift.id]);
  const checkout = useAction();

  const refresh = () => {
    reloadSummary();
    trxList.reload();
  };

  const addToCart = (product) =>
    setCart((items) => {
      const found = items.find((i) => i.id === product.id);
      return found
        ? items.map((i) => (i.id === product.id ? { ...i, quantity: i.quantity + 1 } : i))
        : [...items, { id: product.id, name: product.name, sku: product.sku, price: product.price, quantity: 1 }];
    });
  const setQty = (id, quantity) =>
    setCart((items) => (quantity < 1 ? items.filter((i) => i.id !== id) : items.map((i) => (i.id === id ? { ...i, quantity } : i))));

  const preview = previewTotals(cart, discount);

  const createTransaction = () =>
    checkout.run(async () => {
      const res = await posApi('/transactions', {
        method: 'POST',
        body: { items: cart.map((i) => ({ product_id: i.id, quantity: i.quantity })), discount_amount: discount || 0 },
      });
      setCart([]);
      setDiscount('');
      setPaying(res.data);
      trxList.reload();
    });

  return (
    <>
      <div className="shift-bar card">
        <div>
          <strong>Shift #{shift.id}</strong> · {shift.outlet?.code} · {shortDate(shift.business_date)} · dibuka {dateTime(shift.opened_at)}
        </div>
        <div className="shift-stats">
          <span>Modal {rupiah(shift.opening_cash)}</span>
          <span>Transaksi {summary.trx_count}</span>
          <span>Kas seharusnya {rupiah(summary.expected_cash)}</span>
          {summary.unsynced_total > 0 && <Badge kind="sync" value="failed" label={`${summary.unsynced_total} belum synced`} />}
          <button className="btn btn-danger btn-sm" onClick={() => setClosing(true)}>
            Tutup Shift
          </button>
        </div>
      </div>

      {lastPaid && <PaidNotice trx={lastPaid} onDismiss={() => setLastPaid(null)} />}

      <div className="cashier-grid">
        <ProductPicker onPick={addToCart} />

        <section className="card cart">
          <h2>Keranjang</h2>
          {cart.length === 0 ? (
            <Empty>Pilih produk di sebelah kiri</Empty>
          ) : (
            <table className="table compact">
              <tbody>
                {cart.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <div>{item.name}</div>
                      <div className="muted small">{rupiah(item.price)}</div>
                    </td>
                    <td className="qty">
                      <button className="btn btn-sm" onClick={() => setQty(item.id, item.quantity - 1)}>−</button>
                      <input
                        type="number"
                        min="1"
                        value={item.quantity}
                        onChange={(e) => setQty(item.id, Math.max(1, parseInt(e.target.value, 10) || 1))}
                      />
                      <button className="btn btn-sm" onClick={() => setQty(item.id, item.quantity + 1)}>+</button>
                    </td>
                    <td className="num">{rupiah(Number(item.price) * item.quantity)}</td>
                    <td>
                      <button className="icon-btn" title="Hapus" onClick={() => setQty(item.id, 0)}>×</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <Field label="Diskon (nominal)">
            <input type="number" min="0" step="0.01" value={discount} onChange={(e) => setDiscount(e.target.value)} />
          </Field>
          <dl className="totals">
            <dt>Subtotal</dt>
            <dd>{rupiah(preview.subtotal)}</dd>
            <dt>Diskon</dt>
            <dd>− {rupiah(preview.discount)}</dd>
            <dt>PPN {Math.round(TAX_RATE * 100)}%</dt>
            <dd>{rupiah(preview.tax)}</dd>
            <dt className="grand">Total</dt>
            <dd className="grand">{rupiah(preview.total)}</dd>
          </dl>
          <p className="muted small">Angka final dihitung oleh server saat transaksi dibuat.</p>
          <ErrorAlert error={checkout.error} />
          <button className="btn btn-primary btn-block" disabled={cart.length === 0 || checkout.busy} onClick={createTransaction}>
            {checkout.busy ? 'Memproses…' : 'Bayar'}
          </button>
        </section>
      </div>

      <section className="card">
        <h2>Transaksi shift ini</h2>
        <ErrorAlert error={trxList.error} />
        <ShiftTransactions rows={trxList.data || []} onPay={setPaying} />
      </section>

      {paying && (
        <PaymentModal
          trx={paying}
          onClose={() => {
            setPaying(null);
            refresh();
          }}
          onPaid={(trx) => {
            setPaying(null);
            setLastPaid(trx);
            refresh();
          }}
        />
      )}
      {closing && (
        <CloseShiftModal
          shift={shift}
          summary={summary}
          onClose={() => setClosing(false)}
          onClosed={(result) => {
            setClosing(false);
            onClosed(result);
          }}
        />
      )}
    </>
  );
}

function ProductPicker({ onPick }) {
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  useEffect(() => {
    const t = setTimeout(() => setQuery(search), 250);
    return () => clearTimeout(t);
  }, [search]);
  const products = useApi(() => posApi('/products', { query: { search: query, is_active: 1, per_page: 60 } }).then((r) => r.data), [query]);

  return (
    <section className="card products">
      <input className="search" placeholder="Cari produk / SKU…" value={search} onChange={(e) => setSearch(e.target.value)} />
      <ErrorAlert error={products.error} />
      <div className="product-grid">
        {(products.data || []).map((p) => (
          <button key={p.id} className="product-tile" onClick={() => onPick(p)}>
            <span className="product-name">{p.name}</span>
            <span className="muted small">{p.sku}</span>
            <span className="product-price">{rupiah(p.price)}</span>
          </button>
        ))}
        {products.data?.length === 0 && <Empty>Produk tidak ditemukan</Empty>}
      </div>
    </section>
  );
}

function ShiftTransactions({ rows, onPay }) {
  if (rows.length === 0) return <Empty>Belum ada transaksi</Empty>;
  return (
    <div className="table-scroll">
      <table className="table">
        <thead>
          <tr>
            <th>No. Transaksi</th>
            <th>Status</th>
            <th>Metode</th>
            <th className="num">Total</th>
            <th>Sync Finance</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {rows.map((t) => (
            <tr key={t.id}>
              <td>
                <Link to={`/transaksi/${t.id}`}>{t.trx_number}</Link>
              </td>
              <td>
                <Badge kind="trx" value={t.status} />
              </td>
              <td>{METHOD_LABELS[t.payment_method] || '-'}</td>
              <td className="num">{rupiah(t.grand_total)}</td>
              <td>
                <Badge kind="sync" value={t.finance_sync_status} />
              </td>
              <td>
                {t.status === 'pending' && (
                  <button className="btn btn-sm btn-primary" onClick={() => onPay(t)}>
                    Bayar
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PaymentModal({ trx, onClose, onPaid }) {
  const [method, setMethod] = useState('cash');
  const [paid, setPaid] = useState('');
  const { busy, error, run } = useAction();
  const cash = method === 'cash';
  const paidAmount = cash ? paid : trx.grand_total;
  const change = cash && paid !== '' ? subtractMoney(paid, trx.grand_total) : null;

  const submit = (e) => {
    e.preventDefault();
    run(async () => {
      const res = await posApi(`/transactions/${trx.id}/pay`, { method: 'POST', body: { payment_method: method, paid_amount: paidAmount } });
      onPaid(res.data);
    });
  };

  return (
    <Modal
      title={`Pembayaran ${trx.trx_number}`}
      onClose={onClose}
      footer={
        <>
          <button className="btn" onClick={onClose} type="button">
            Nanti
          </button>
          <button className="btn btn-primary" form="pay-form" disabled={busy || (cash && Number(change) < 0)}>
            {busy ? 'Memproses…' : 'Konfirmasi Bayar'}
          </button>
        </>
      }
    >
      <form id="pay-form" onSubmit={submit}>
        <dl className="totals">
          <dt>Subtotal</dt>
          <dd>{rupiah(trx.subtotal)}</dd>
          <dt>Diskon</dt>
          <dd>− {rupiah(trx.discount_amount)}</dd>
          <dt>PPN</dt>
          <dd>{rupiah(trx.tax_amount)}</dd>
          <dt className="grand">Total</dt>
          <dd className="grand">{rupiah(trx.grand_total)}</dd>
        </dl>
        <div className="segmented">
          {Object.entries(METHOD_LABELS).map(([value, label]) => (
            <button type="button" key={value} className={method === value ? 'active' : ''} onClick={() => setMethod(value)}>
              {label}
            </button>
          ))}
        </div>
        {cash ? (
          <>
            <Field label="Uang diterima" error={error?.fields?.paid_amount}>
              <input type="number" min="0" step="0.01" value={paid} onChange={(e) => setPaid(e.target.value)} required autoFocus />
            </Field>
            <div className={`change ${Number(change) < 0 ? 'negative' : ''}`}>
              Kembalian: <strong>{change === null ? '-' : Number(change) < 0 ? 'Uang kurang' : rupiah(change)}</strong>
            </div>
          </>
        ) : (
          <p className="muted">Nominal {METHOD_LABELS[method]} harus sama dengan total: {rupiah(trx.grand_total)}</p>
        )}
        <ErrorAlert error={error} />
      </form>
    </Modal>
  );
}

function PaidNotice({ trx, onDismiss }) {
  const failed = trx.finance_sync_status !== 'synced';
  return (
    <Alert type={failed ? 'warning' : 'success'}>
      <div className="notice-row">
        <div>
          <strong>{trx.trx_number}</strong> lunas ({METHOD_LABELS[trx.payment_method]}) · Total {rupiah(trx.grand_total)}
          {trx.payment_method === 'cash' && <> · Kembalian <strong>{rupiah(trx.change_amount)}</strong></>}
          {failed && (
            <div className="small">
              Posting ke Finance gagal ({trx.finance_last_error}). Transaksi tetap lunas; kirim ulang dari{' '}
              <Link to={`/transaksi/${trx.id}`}>detail transaksi</Link>.
            </div>
          )}
        </div>
        <button className="icon-btn" onClick={onDismiss}>×</button>
      </div>
    </Alert>
  );
}

function CloseShiftModal({ shift, summary, onClose, onClosed }) {
  const [actual, setActual] = useState('');
  const { busy, error, run } = useAction();
  const variance = actual === '' ? null : subtractMoney(actual, summary.expected_cash);

  const submit = (e) => {
    e.preventDefault();
    run(async () => {
      const res = await posApi(`/shifts/${shift.id}/close`, { method: 'POST', body: { actual_cash: actual } });
      onClosed(res.data);
    });
  };

  return (
    <Modal
      title={`Tutup Shift #${shift.id}`}
      onClose={onClose}
      footer={
        <>
          <button className="btn" type="button" onClick={onClose}>
            Batal
          </button>
          <button className="btn btn-danger" form="close-form" disabled={busy || actual === ''}>
            {busy ? 'Menutup…' : 'Konfirmasi Tutup Shift'}
          </button>
        </>
      }
    >
      <form id="close-form" onSubmit={submit}>
        {summary.pending_count > 0 && (
          <Alert type="warning">Masih ada {summary.pending_count} transaksi pending — selesaikan pembayaran sebelum menutup shift.</Alert>
        )}
        <dl className="totals">
          <dt>Modal awal</dt>
          <dd>{rupiah(shift.opening_cash)}</dd>
          <dt>Penjualan tunai</dt>
          <dd>{rupiah(summary.totals_by_method.cash.total)}</dd>
          <dt className="grand">Kas seharusnya</dt>
          <dd className="grand">{rupiah(summary.expected_cash)}</dd>
        </dl>
        <Field label="Kas fisik dihitung (actual cash)" error={error?.fields?.actual_cash}>
          <input type="number" min="0" step="0.01" value={actual} onChange={(e) => setActual(e.target.value)} required autoFocus />
        </Field>
        {variance !== null && (
          <div className={`change ${isNonZero(variance) ? 'negative' : ''}`}>
            Selisih: <strong>{rupiah(variance)}</strong>
          </div>
        )}
        <ErrorAlert error={error} />
      </form>
    </Modal>
  );
}

function ClosedShiftNotice({ result, onDismiss }) {
  const shift = result.shift;
  return (
    <Alert type={result.unsynced_total > 0 || isNonZero(shift.cash_variance) ? 'warning' : 'success'}>
      <div className="notice-row">
        <div>
          <strong>Shift #{shift.id} ditutup.</strong> Kas seharusnya {rupiah(shift.expected_cash)} · Kas fisik {rupiah(shift.actual_cash)} · Selisih{' '}
          <strong>{rupiah(shift.cash_variance)}</strong>
          {result.warning && <div className="small">{result.warning}</div>}
        </div>
        <button className="icon-btn" onClick={onDismiss}>×</button>
      </div>
      <div className="stats small-stats">
        <Stat label="Transaksi" value={result.trx_count} />
        <Stat label="Void" value={result.void_count} />
        <Stat label="Belum synced" value={result.unsynced_count} tone={result.unsynced_count ? 'danger' : undefined} />
      </div>
    </Alert>
  );
}
