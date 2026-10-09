import { useMemo, useState } from 'react';
import type { ReportParams, User } from '../../api';
import { LiveVisitors } from '../../components/LiveVisitors';
import { PeriodPicker } from '../../components/PeriodPicker';
import { Snippet } from '../../components/Snippet';
import { filtersFrom, navigate, withFilter, withParam } from '../../route';
import { displayValue, section as findSection } from '../../sections';
import { DimensionSection } from './DimensionSection';
import { EventsSection } from './EventsSection';
import { OverviewSection } from './OverviewSection';
import { RealtimeSection } from './RealtimeSection';

const FILTER_LABELS: Record<string, string> = {
  page: 'Page', source: 'Source', referrer: 'Referrer', utm_source: 'UTM source', utm_medium: 'UTM medium',
  utm_campaign: 'UTM campaign', utm_term: 'UTM term', utm_content: 'UTM content', country: 'Country',
  region: 'Region', city: 'City', browser: 'Browser', os: 'OS', device: 'Device', event: 'Event', hostname: 'Hostname',
};

export interface SiteContext {
  site: string;
  report: ReportParams;
  params: URLSearchParams;
  go: (params: URLSearchParams, sectionId?: string) => void;
  filter: (dimension: string, value: string) => void;
  href: (sectionId: string, extra?: Record<string, string>) => string;
}

interface Props {
  site: string;
  sectionId?: string;
  params: URLSearchParams;
  publicUrl: string;
  user: User;
}

/** One site: header (period, filters, live visitors, snippet) and the chosen section. */
export function SiteView({ site, sectionId, params, publicUrl, user }: Props) {
  const current = findSection(sectionId);
  const period = params.get('period') || '7d';
  const filters = filtersFrom(params);
  const filterKey = JSON.stringify(filters);
  const report: ReportParams = useMemo(() => ({ site, period, filters }), [site, period, filterKey]); // eslint-disable-line react-hooks/exhaustive-deps
  const [showSnippet, setShowSnippet] = useState(false);

  const ctx: SiteContext = {
    site, report, params,
    go: (next, id = current.id) => navigate(`site/${encodeURIComponent(site)}/${id}`, next),
    filter: (dimension, value) => navigate(`site/${encodeURIComponent(site)}/${current.id}`, withFilter(params, dimension, value)),
    href: (id, extra = {}) => {
      const next = new URLSearchParams(params);
      next.delete('tab');
      for (const [k, v] of Object.entries(extra)) next.set(k, v);
      return `#/site/${encodeURIComponent(site)}/${id}${next.toString() ? `?${next}` : ''}`;
    },
  };

  return (
    <>
      <header className="page-head">
        <div className="page-title">
          <h1>{current.label}</h1>
          <span className="muted">{site}</span>
        </div>
        <span className="spacer" />
        <LiveVisitors site={site} />
        <button className="btn" onClick={() => setShowSnippet((s) => !s)} aria-expanded={showSnippet}>Tracking snippet</button>
      </header>

      {current.id !== 'realtime' && (
        <div className="controls" role="toolbar" aria-label="Report filters">
          <PeriodPicker value={period} onChange={(p) => ctx.go(withParam(params, 'period', p))} />
          {Object.entries(filters).map(([dimension, value]) => (
            <span className="chip" key={dimension}>
              <span className="muted">{FILTER_LABELS[dimension] ?? dimension}</span> {displayValue(dimension, value)}
              <button aria-label={`Remove ${dimension} filter`} onClick={() => ctx.go(withFilter(params, dimension, null))}>×</button>
            </span>
          ))}
        </div>
      )}

      {showSnippet && (
        <div className="card" style={{ marginBottom: 16 }}>
          Add this to the <code>&lt;head&gt;</code> of every page on {site}:
          <Snippet domain={site} publicUrl={publicUrl} />
        </div>
      )}

      {current.id === 'overview' && <OverviewSection ctx={ctx} />}
      {current.tabs && <DimensionSection key={current.id} ctx={ctx} section={current} />}
      {current.id === 'events' && <EventsSection ctx={ctx} canManage={user.is_admin} />}
      {current.id === 'realtime' && <RealtimeSection site={site} />}
    </>
  );
}
