import { Link, useParams } from 'react-router-dom';
import { financeApi } from '../api/client';
import { Badge, ErrorAlert, Loading, PageHeader } from '../components/ui';
import { METHOD_LABELS, dateTime, rupiah, shortDate } from '../lib/format';
import { useApi } from '../lib/useApi';

export default function PostingDetail() {
  const { id } = useParams();
  const posting = useApi(() => financeApi(`/postings/${id}`).then((r) => r.data), [id]);

  if (posting.loading && !posting.data) return <Loading />;
  if (!posting.data) return <ErrorAlert error={posting.error} />;
  const p = posting.data;

  return (
    <>
      <PageHeader title={`${p.trx_number}`} subtitle={<Link to="/postings">‹ Daftar posting</Link>} />
      <section className="card">
        <dl className="kv kv-inline">
          <dt>Jenis</dt>
          <dd><Badge kind="entry" value={p.entry_type} /></dd>
          <dt>Idempotency key</dt>
          <dd><code>{p.idempotency_key}</code></dd>
          <dt>Outlet</dt>
          <dd>{p.outlet_code} (#{p.outlet_id})</dd>
          <dt>Shift</dt>
          <dd>#{p.shift_id}</dd>
          <dt>Tanggal bisnis</dt>
          <dd>{shortDate(p.business_date)}</dd>
          <dt>Metode</dt>
          <dd>{METHOD_LABELS[p.payment_method]}</dd>
          <dt>Diposting</dt>
          <dd>{dateTime(p.posted_at)}</dd>
        </dl>
      </section>

      <section className="card">
        <div className="card-title-row">
          <h2>Jurnal</h2>
          <Badge kind="active" value={p.is_balanced} label={p.is_balanced ? '✓ Seimbang' : '✗ Tidak seimbang'} />
        </div>
        <table className="table">
          <thead>
            <tr>
              <th>Akun</th>
              <th>Nama akun</th>
              <th className="num">Debit</th>
              <th className="num">Kredit</th>
            </tr>
          </thead>
          <tbody>
            {p.lines.map((l) => (
              <tr key={l.id}>
                <td>{l.account_code}</td>
                <td className={Number(l.credit) > 0 ? 'indent' : ''}>{l.account?.name}</td>
                <td className="num">{Number(l.debit) ? rupiah(l.debit) : ''}</td>
                <td className="num">{Number(l.credit) ? rupiah(l.credit) : ''}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="grand">
              <td colSpan={2}>Total</td>
              <td className="num">{rupiah(p.total_debit)}</td>
              <td className="num">{rupiah(p.total_credit)}</td>
            </tr>
          </tfoot>
        </table>
      </section>
    </>
  );
}
