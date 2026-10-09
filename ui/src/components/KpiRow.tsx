import type { Metrics, Overview } from '../api';
import { formatChange, formatDuration, formatNumber, formatPercent } from '../format';

export type ChartMetric = 'visitors' | 'visits' | 'pageviews' | 'events';

interface Tile {
  key: keyof Metrics;
  label: string;
  format: (v: number) => string;
  upIsGood: boolean;
  chart?: ChartMetric;
}

const TILES: Tile[] = [
  { key: 'visitors', label: 'Visitors', format: formatNumber, upIsGood: true, chart: 'visitors' },
  { key: 'visits', label: 'Visits', format: formatNumber, upIsGood: true, chart: 'visits' },
  { key: 'pageviews', label: 'Pageviews', format: formatNumber, upIsGood: true, chart: 'pageviews' },
  { key: 'bounce_rate', label: 'Bounce rate', format: formatPercent, upIsGood: false },
  { key: 'visit_duration', label: 'Visit duration', format: formatDuration, upIsGood: true },
];

interface Props {
  overview: Overview;
  metric: ChartMetric;
  onMetric: (metric: ChartMetric) => void;
}

/** Stat tiles; the ones with a time series select what the chart shows. */
export function KpiRow({ overview, metric, onMetric }: Props) {
  return (
    <div className="kpis" role="group" aria-label="Key metrics">
      {TILES.map((tile) => {
        const change = overview.change[tile.key];
        const label = formatChange(change);
        const tone = change === null || Math.round(change) === 0 ? '' : (change > 0) === tile.upIsGood ? 'good' : 'bad';
        const content = (
          <>
            <div className="kpi-label">{tile.label}</div>
            <div className="kpi-value">{tile.format(overview.current[tile.key])}</div>
            <div className={`kpi-delta ${tone}`}>
              {label === null ? 'no earlier data' : `${tone === 'good' ? '▲' : tone === 'bad' ? '▼' : ''} ${label} vs previous`}
            </div>
          </>
        );
        return tile.chart ? (
          <button key={tile.key} className="kpi" aria-pressed={metric === tile.chart} onClick={() => onMetric(tile.chart!)}>
            {content}
          </button>
        ) : (
          <div key={tile.key} className="kpi" style={{ cursor: 'default' }}>{content}</div>
        );
      })}
    </div>
  );
}
