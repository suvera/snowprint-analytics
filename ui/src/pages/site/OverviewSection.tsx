import { useEffect, useState } from 'react';
import { api, type Overview, type Series } from '../../api';
import { Breakdown } from '../../components/Breakdown';
import { KpiRow, type ChartMetric } from '../../components/KpiRow';
import { LineChart } from '../../components/LineChart';
import { withParam } from '../../route';
import { SECTIONS } from '../../sections';
import type { SiteContext } from './SiteView';

const METRIC_LABEL: Record<ChartMetric, string> = { visitors: 'Visitors', visits: 'Visits', pageviews: 'Pageviews', events: 'Events' };

/** KPIs, the trend chart and top-5 previews of every section. */
export function OverviewSection({ ctx }: { ctx: SiteContext }) {
  const metric = (ctx.params.get('metric') as ChartMetric) || 'visitors';
  const [overview, setOverview] = useState<Overview | null>(null);
  const [series, setSeries] = useState<Series | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    Promise.all([api.overview(ctx.report, controller.signal), api.timeseries(ctx.report, metric, controller.signal)])
      .then(([o, s]) => { setOverview(o); setSeries(s); setLoading(false); })
      .catch((e) => { if (e.name !== 'AbortError') { setError(e.message); setLoading(false); } });
    return () => controller.abort();
  }, [ctx.report, metric]);

  return (
    <>
      {error && <div className="error" role="alert">{error}</div>}
      {overview && series && (
        <section className={`card ${loading ? 'refreshing' : ''}`} aria-busy={loading}>
          <KpiRow overview={overview} metric={metric} onMetric={(m) => ctx.go(withParam(ctx.params, 'metric', m))} />
          <LineChart label={METRIC_LABEL[metric]} current={series.series} previous={series.previous} />
          <p className="muted small" style={{ margin: '8px 0 0' }}>
            {overview.period.from === overview.period.to ? overview.period.from : `${overview.period.from} – ${overview.period.to}`} ·
            {' '}{overview.period.timezone} · visitors are counted per day, without cookies
          </p>
        </section>
      )}
      <div className="panels">
        {SECTIONS.filter((s) => s.tabs).map((s) => (
          <Breakdown key={s.id} title={s.label} tabs={s.tabs!.slice(0, 3).map(({ dimension, label, column }) => ({ dimension, label, column }))} report={ctx.report} onFilter={ctx.filter}
            viewAll={(dimension) => ctx.href(s.id, { tab: dimension })} />
        ))}
      </div>
    </>
  );
}
