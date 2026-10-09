import { useEffect, useState } from 'react';
import { api } from '../api';
import { formatFull } from '../format';

/** Visitors in the last 5 minutes, refreshed every 30 seconds. */
export function LiveVisitors({ site, share }: { site: string; share?: string }) {
  const [count, setCount] = useState<number | null>(null);
  useEffect(() => {
    let controller = new AbortController();
    const load = () => {
      controller.abort();
      controller = new AbortController();
      api.realtime(site, controller.signal, share).then((r) => setCount(r.visitors)).catch(() => undefined);
    };
    load();
    const timer = window.setInterval(load, 30_000);
    return () => { window.clearInterval(timer); controller.abort(); };
  }, [site, share]);
  if (count === null) return null;
  return (
    <span className="live" title="Visitors in the last 5 minutes">
      <span className="live-dot" aria-hidden="true" />
      {formatFull(count)} current {count === 1 ? 'visitor' : 'visitors'}
    </span>
  );
}
