// Role-based access matrix (mirrors the backend; the backend remains the source of truth).

export const ROLE_LABELS = {
  superadmin: 'Superadmin',
  kasir: 'Kasir',
  supervisor_pos: 'Supervisor POS',
  staff_finance: 'Staff Finance',
  manager_finance: 'Manager Finance',
};

export const ROLES = Object.keys(ROLE_LABELS);

const PERMISSIONS = {
  dashboard: ROLES,
  posDashboard: ['kasir', 'supervisor_pos', 'superadmin'],
  cashier: ['kasir', 'superadmin'],
  transactions: ['kasir', 'supervisor_pos', 'superadmin'],
  voidTransaction: ['supervisor_pos', 'superadmin'],
  resyncFinance: ['kasir', 'supervisor_pos', 'superadmin'],
  products: ['supervisor_pos', 'superadmin'],
  finance: ['staff_finance', 'manager_finance', 'superadmin'],
  resolveMismatch: ['manager_finance', 'superadmin'],
  users: ['superadmin'],
};

export function can(user, permission) {
  return Boolean(user && PERMISSIONS[permission]?.includes(user.role));
}

export const MENU = [
  { section: 'Umum', to: '/', label: 'Dashboard', permission: 'dashboard', end: true },
  { section: 'POS', to: '/kasir', label: 'Layar Kasir', permission: 'cashier' },
  { section: 'POS', to: '/transaksi', label: 'Riwayat Transaksi', permission: 'transactions' },
  { section: 'POS', to: '/produk', label: 'Manajemen Produk', permission: 'products' },
  { section: 'Finance', to: '/postings', label: 'Posting & Jurnal', permission: 'finance' },
  { section: 'Finance', to: '/rekonsiliasi', label: 'Rekonsiliasi EOD', permission: 'finance' },
  { section: 'Admin', to: '/users', label: 'Manajemen User', permission: 'users' },
];
