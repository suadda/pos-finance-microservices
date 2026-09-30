import { Route, Routes } from 'react-router-dom';
import RequireAuth from './auth/RequireAuth';
import Layout from './components/Layout';
import Cashier from './pages/Cashier';
import Dashboard from './pages/Dashboard';
import { Forbidden, NotFound } from './pages/ErrorPages';
import Login from './pages/Login';
import PostingDetail from './pages/PostingDetail';
import Postings from './pages/Postings';
import Products from './pages/Products';
import ReconciliationDetail from './pages/ReconciliationDetail';
import Reconciliations from './pages/Reconciliations';
import TransactionDetail from './pages/TransactionDetail';
import Transactions from './pages/Transactions';
import Users from './pages/Users';

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<RequireAuth />}>
        <Route element={<Layout />}>
          <Route index element={<Dashboard />} />
          <Route path="forbidden" element={<Forbidden />} />

          <Route element={<RequireAuth permission="cashier" />}>
            <Route path="kasir" element={<Cashier />} />
          </Route>
          <Route element={<RequireAuth permission="transactions" />}>
            <Route path="transaksi" element={<Transactions />} />
            <Route path="transaksi/:id" element={<TransactionDetail />} />
          </Route>
          <Route element={<RequireAuth permission="products" />}>
            <Route path="produk" element={<Products />} />
          </Route>
          <Route element={<RequireAuth permission="finance" />}>
            <Route path="postings" element={<Postings />} />
            <Route path="postings/:id" element={<PostingDetail />} />
            <Route path="rekonsiliasi" element={<Reconciliations />} />
            <Route path="rekonsiliasi/:id" element={<ReconciliationDetail />} />
          </Route>
          <Route element={<RequireAuth permission="users" />}>
            <Route path="users" element={<Users />} />
          </Route>
          <Route path="*" element={<NotFound />} />
        </Route>
      </Route>
    </Routes>
  );
}
