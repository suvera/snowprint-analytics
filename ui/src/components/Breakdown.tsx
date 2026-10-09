import { useEffect, useState } from 'react';
import { api, type BreakdownRow, type ReportParams } from '../api';
import { formatFull, formatNumber } from '../format';
import { displayValue } from '../sections';

export interface BreakdownTab {
  dimension: string;   // API dimension
  label: string;       // tab label
  column: string;      // first column heading
  filter?: string;     // filter key a click adds (defaults to dimension; none for entry/exit)
}

interface Props {
  title: string;
  tabs: BreakdownTab[];
  report: ReportParams;
  onFilter: (dimension: string, value: string) => void;
  viewAll?: (dimension: string) => string;   // href of the full section for a tab
}

/**
 * A top-N list: one-hue horizontal bars (magnitude) with ink labels on top and
 * the visitor count at the end. Clicking a row filters the whole dashboard.
 */
export function Breakdown({ title, tabs, report, onFilter, viewAll }: Props) {
  const [tab, setTab] = useState(0);
  const [rows, setRows] = useState<BreakdownRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const active = tabs[tab];

  useEffect(() => {
    const controller = new AbortController();
    setError(null);
    api.breakdown(report, active.dimension, 9, controller.signal)
      .then((r) => setRows(r.rows))
      .catch((e) => { if (e.name !== 'AbortError') setError(e.message); });
    return () => controller.abort();
  }, [report, active.dimension]);

  const max = Math.max(1, ...(rows ?? []).map((r) => r.visitors));
  const filterKey = active.filter ?? active.dimension;
  const clickable = active.dimension !== 'entry_page' && active.dimension !== 'exit_page';

  return (
    <section className="card" aria-label={title}>
      <div className="panel-head">
        <span className="panel-title">{title}</span>
        {tabs.length > 1 && tabs.map((t, i) => (
          <button key={t.dimension} className="tab" role="tab" aria-selected={i === tab} onClick={() => setTab(i)}>{t.label}</button>
        ))}
      </div>
      {error ? <div className="error">{error}</div> : rows === null ? <div className="empty">Loading…</div> : rows.length === 0 ? (
        <div className="empty">No data yet</div>
      ) : (
        <>
          <div className="row-head"><span>{active.column}</span><span>Visitors</span></div>
          <ul className="rows">
            {rows.map((row) => (
              <li className="row" key={row.value}>
                <button
                  className="row-bar"
                  title={`${row.value}: ${formatFull(row.visitors)} visitors, ${formatFull(row.pageviews)} pageviews`}
                  disabled={!clickable || row.value === '(none)'}
                  onClick={() => onFilter(filterKey, row.value)}
                >
                  <span className="row-fill" style={{ width: `${(row.visitors / max) * 100}%` }} />
                  <span className="row-label">{displayValue(active.dimension, row.value)}</span>
                </button>
                <span className="row-value">{formatNumber(row.visitors)}</span>
              </li>
            ))}
          </ul>
          {viewAll && <a className="view-all small" href={viewAll(active.dimension)}>View all →</a>}
        </>
      )}
    </section>
  );
}
