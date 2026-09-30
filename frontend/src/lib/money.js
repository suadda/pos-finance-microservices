// Client-side *preview* of the server's pricing rules, in integer cents to avoid float errors.
// Final figures always come from the POS Service.

export const TAX_RATE = Number(import.meta.env.VITE_TAX_RATE || 0.11);

const toCents = (value) => Math.round(Number(value || 0) * 100);
const fromCents = (cents) => (cents / 100).toFixed(2);

export function previewTotals(lines, discount) {
  const subtotal = lines.reduce((sum, l) => sum + toCents(l.price) * l.quantity, 0);
  const disc = Math.min(Math.max(toCents(discount), 0), subtotal);
  const tax = Math.round((subtotal - disc) * TAX_RATE);
  return {
    subtotal: fromCents(subtotal),
    discount: fromCents(disc),
    tax: fromCents(tax),
    total: fromCents(subtotal - disc + tax),
  };
}

export function subtractMoney(a, b) {
  return fromCents(toCents(a) - toCents(b));
}
