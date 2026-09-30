const rupiahFormatter = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});

/** "111000.00" -> "Rp 111.000" (API money values are decimal strings). */
export function rupiah(value) {
  if (value === null || value === undefined || value === '') return '-';
  return rupiahFormatter.format(Number(value));
}

export function dateTime(value) {
  if (!value) return '-';
  return new Date(value).toLocaleString('id-ID', { timeZone: 'Asia/Jakarta', dateStyle: 'medium', timeStyle: 'short' });
}

export function shortDate(value) {
  if (!value) return '-';
  return new Date(`${value}T00:00:00+07:00`).toLocaleDateString('id-ID', { timeZone: 'Asia/Jakarta', day: '2-digit', month: 'short', year: 'numeric' });
}

/** Today's business date in Asia/Jakarta as YYYY-MM-DD. */
export function todayJakarta(offsetDays = 0) {
  const d = new Date(Date.now() + offsetDays * 86400000);
  return d.toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });
}

export const METHOD_LABELS = { cash: 'Tunai', debit: 'Debit', qris: 'QRIS' };

export function isNonZero(value) {
  return Number(value) !== 0;
}
