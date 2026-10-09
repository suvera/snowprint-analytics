import { useEffect, useState } from 'react';
import { api, type BreakdownRow } from '../../api';
import { BarList } from '../../components/BarList';
import { DataTable } from '../../components/DataTable';
import { withParam } from '../../route';
import type { Section } from '../../sections';
import type { SiteContext } from './SiteView';

/**
 * One section (Sources, Pages, ...): tabs for its dimensions, the top 10 as
 * bars, and every value in a sortable table with share, change vs the previous
 * period, visits, pageviews, bounce rate and visit duration.
 */
export function DimensionSection({ ctx, section }: { ctx: SiteContext; section: Section }) {
  const tabs = section.tabs!;
  const active = tabs.find((t) => t.dimension === ctx.params.get('tab')) ?? tabs[0];
  const [rows, setRows] = useState<BreakdownRow[] | null>(null);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    Promise.all([
      api.breakdown(ctx.report, active.dimension, 100, controller.signal, true),
      api.overview(ctx.report, controller.signal),
    ]).then(([b, o]) => { setRows(b.rows); setTotal(o.current.visitors); setLoading(false); })
      .catch((e) => { if (e.name !== 'AbortError') { setError(e.message); setLoading(false); } });
    return () => controller.abort();
  }, [ctx.report, active.dimension]);

  const onFilter = active.filter === false ? undefined : (value: string) => ctx.filter(active.dimension, value);

  return (
    <>
      <div className="tabs" role="tablist" aria-label={`${section.label} dimensions`}>
        {tabs.map((t) => (
          <button key={t.dimension} role="tab" className="tab" aria-selected={t.dimension === active.dimension}
            onClick={() => ctx.go(withParam(ctx.params, 'tab', t.dimension))}>{t.label}</button>
        ))}
      </div>
      {error && <div className="error" role="alert">{error}</div>}
      {rows && (
        <div className={`detail-grid ${loading ? 'refreshing' : ''}`} aria-busy={loading}>
          <section className="card">
            <div className="panel-head"><span className="panel-title">Top 10 · {active.column}</span><span className="muted small">visitors</span></div>
            <BarList rows={rows} dimension={active.dimension} onFilter={onFilter} />
          </section>
          <section className="card">
            <DataTable rows={rows} dimension={active.dimension} column={active.column} totalVisitors={total} onFilter={onFilter} />
          </section>
        </div>
      )}
    </>
  );
}
