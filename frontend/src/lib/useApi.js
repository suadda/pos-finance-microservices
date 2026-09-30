import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Runs `fetcher` on mount and whenever `deps` change.
 * Returns { data, error, loading, reload }. Stale responses are ignored.
 */
export function useApi(fetcher, deps = []) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const seq = useRef(0);

  const reload = useCallback(() => {
    const id = ++seq.current;
    setState((s) => ({ ...s, loading: true, error: null }));
    return fetcher()
      .then((data) => {
        if (id === seq.current) setState({ data, error: null, loading: false });
        return data;
      })
      .catch((error) => {
        if (id === seq.current) setState((s) => ({ ...s, error, loading: false }));
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  useEffect(() => {
    reload();
  }, [reload]);

  return { ...state, reload };
}

/** Wraps an async action with busy/error state for buttons and forms. */
export function useAction() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const run = useCallback(async (fn) => {
    setBusy(true);
    setError(null);
    try {
      return await fn();
    } catch (e) {
      setError(e);
      return undefined;
    } finally {
      setBusy(false);
    }
  }, []);

  return { busy, error, setError, run };
}
