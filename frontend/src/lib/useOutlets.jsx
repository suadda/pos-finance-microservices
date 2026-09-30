import { useEffect, useState } from 'react';
import { posApi } from '../api/client';

let cache = null;

/** Active outlets (GET /pos/outlets, readable by every role), cached for the session. */
export function useOutlets() {
  const [outlets, setOutlets] = useState(cache || []);

  useEffect(() => {
    if (cache) return;
    posApi('/outlets')
      .then((res) => {
        cache = res.data;
        setOutlets(res.data);
      })
      .catch(() => {});
  }, []);

  const nameOf = (id) => {
    const o = outlets.find((x) => x.id === Number(id));
    return o ? `${o.code} — ${o.name}` : id ? `#${id}` : '-';
  };

  return { outlets, nameOf };
}

export function OutletSelect({ value, onChange, allowAll = true, required = false, outlets }) {
  return (
    <select value={value ?? ''} onChange={(e) => onChange(e.target.value)} required={required}>
      {allowAll && <option value="">Semua outlet</option>}
      {!allowAll && <option value="">Pilih outlet…</option>}
      {outlets.map((o) => (
        <option key={o.id} value={o.id}>
          {o.code} — {o.name}
        </option>
      ))}
    </select>
  );
}
