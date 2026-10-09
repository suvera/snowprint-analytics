import { useEffect, useState } from 'react';
import { api, type Realtime } from '../../api';
import { formatFull } from '../../format';

/** Visitors and their pages in the last 5 minutes, refreshed every 15 seconds. */
export function RealtimeSection({ site }: { site: string }) {
  const [data, setData] = useState<Realtime | null>(null);
  const [updated, setUpdated] = useState<Date | null>(null);

  useEffect(() => {
    let controller = new AbortController();
    const load = () => {
      controller.abort();
      controller = new AbortController();
      api.realtime(site, controller.signal).then((r) => { setData(r); setUpdated(new Date()); }).catch(() => undefined);
    };
    load();
    const timer = window.setInterval(load, 15_000);
    return () => { window.clearInterval(timer); controller.abort(); };
  }, [site]);

  const max = Math.max(1, ...(data?.pages ?? []).map((p) => p.visitors));
  return (
    <div className="detail-grid">
      <section className="card hero">
        <div className="kpi-label">Current visitors</div>
        <div className="hero-value">{data ? formatFull(data.visitors) : '–'}</div>
        <div className="muted small">last 5 minutes{updated ? ` · updated ${updated.toLocaleTimeString()}` : ''}</div>
      </section>
      <section className="card">
        <div className="panel-head"><span className="panel-title">Active pages</span><span className="muted small">visitors</span></div>
        {!data || data.pages.length === 0 ? <div className="empty">Nobody is on the site right now</div> : (
          <ol className="barlist">
            {data.pages.map((p) => (
              <li key={p.page} className="barlist-row">
                <div className="barlist-label">{p.page}</div>
                <div className="barlist-track">
                  <span className="barlist-bar" style={{ width: `${Math.max(0.5, (p.visitors / max) * 100)}%` }} />
                  <span className="barlist-value">{formatFull(p.visitors)}</span>
                </div>
              </li>
            ))}
          </ol>
        )}
      </section>
    </div>
  );
}
