import { useMemo, useState } from 'react';
import type { BreakdownRow } from '../api';
import { formatDuration, formatFull } from '../format';
import { displayValue } from '../sections';

type SortKey = 'value' | 'visitors' | 'share' | 'change' | 'visits' | 'pageviews' | 'events' | 'bounce_rate' | 'visit_duration';

interface Props {
  rows: BreakdownRow[];
  dimension: string;
  column: string;                  // heading of the value column
  totalVisitors: number;           // for the share column
  onFilter?: (value: string) => void;
  eventsMode?: boolean;            // custom events: show events instead of pageviews
}

/**
 * Full breakdown table: sortable, searchable, with an inline share bar in the
 * value column (one-hue wash, ink text) and CSV export.
 */
export function DataTable({ rows, dimension, column, totalVisitors, onFilter, eventsMode = false }: Props) {
  const [sort, setSort] = useState<{ key: SortKey; desc: boolean }>({ key: 'visitors', desc: true });
  const [query, setQuery] = useState('');
  const hasChange = rows.some((r) => r.change !== undefined);

  const shown = useMemo(() => {
    const q = query.trim().toLowerCase();
    const filtered = q ? rows.filter((r) => r.value.toLowerCase().includes(q) || displayValue(dimension, r.value).toLowerCase().includes(q)) : rows;
    const value = (r: BreakdownRow): number | string => {
      switch (sort.key) {
        case 'value': return displayValue(dimension, r.value).toLowerCase();
        case 'share': return r.visitors;
        case 'change': return r.change ?? Number.NEGATIVE_INFINITY;
        default: return r[sort.key];
      }
    };
    return [...filtered].sort((a, b) => {
      const va = value(a), vb = value(b);
      const cmp = va < vb ? -1 : va > vb ? 1 : 0;
      return sort.desc ? -cmp : cmp;
    });
  }, [rows, query, sort, dimension]);

  const max = Math.max(1, ...rows.map((r) => r.visitors));
  const header = (key: SortKey, label: string, numeric = true) => (
    <th className={numeric ? 'num' : undefined} aria-sort={sort.key === key ? (sort.desc ? 'descending' : 'ascending') : 'none'}>
      <button className="sort" onClick={() => setSort((s) => ({ key, desc: s.key === key ? !s.desc : key !== 'value' }))}>
        {label}{sort.key === key ? (sort.desc ? ' ↓' : ' ↑') : ''}
      </button>
    </th>
  );

  const exportCsv = () => {
    const cells = [column, 'Visitors', 'Share %', 'Visits', eventsMode ? 'Events' : 'Pageviews', 'Bounce rate %', 'Visit duration s'];
    const lines = [cells, ...shown.map((r) => [displayValue(dimension, r.value), r.visitors, share(r.visitors, totalVisitors),
      r.visits, eventsMode ? r.events : r.pageviews, r.bounce_rate, r.visit_duration])];
    const csv = lines.map((l) => l.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = `snowprint-${dimension}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
  };

  return (
    <div>
      <div className="table-tools">
        <input className="search" type="search" placeholder={`Search ${column.toLowerCase()}`} aria-label={`Search ${column}`}
          value={query} onChange={(e) => setQuery(e.target.value)} />
        <span className="muted small">{formatFull(shown.length)} of {formatFull(rows.length)}</span>
        <span className="spacer" />
        <button className="btn small" onClick={exportCsv} disabled={rows.length === 0}>Export CSV</button>
      </div>
      <div className="table-scroll">
        <table className="data wide">
          <thead>
            <tr>
              {header('value', column, false)}
              {header('visitors', 'Visitors')}
              {header('share', 'Share')}
              {hasChange && header('change', 'Change')}
              {header('visits', 'Visits')}
              {eventsMode ? header('events', 'Events') : header('pageviews', 'Pageviews')}
              {header('bounce_rate', 'Bounce rate')}
              {header('visit_duration', 'Visit duration')}
            </tr>
          </thead>
          <tbody>
            {shown.map((r) => {
              const label = displayValue(dimension, r.value);
              const clickable = onFilter && r.value !== '(none)';
              return (
                <tr key={r.value}>
                  <td className="value-cell">
                    <span className="value-bar" style={{ width: `${(r.visitors / max) * 100}%` }} aria-hidden="true" />
                    {clickable ? (
                      <button className="value-link" title={`Filter by ${label}`} onClick={() => onFilter!(r.value)}>{label}</button>
                    ) : <span className="value-text">{label}</span>}
                  </td>
                  <td className="num">{formatFull(r.visitors)}</td>
                  <td className="num muted">{share(r.visitors, totalVisitors)}%</td>
                  {hasChange && <td className="num"><Change value={r.change} /></td>}
                  <td className="num">{formatFull(r.visits)}</td>
                  <td className="num">{formatFull(eventsMode ? r.events : r.pageviews)}</td>
                  <td className="num">{r.bounce_rate}%</td>
                  <td className="num">{formatDuration(r.visit_duration)}</td>
                </tr>
              );
            })}
            {shown.length === 0 && (
              <tr><td colSpan={8} className="empty">{rows.length === 0 ? 'No data for this period' : 'No matches'}</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function share(visitors: number, total: number): string {
  return total > 0 ? (Math.round((1000 * visitors) / total) / 10).toString() : '0';
}

/** Visitor change vs the previous period: arrow + signed % (never colour alone). */
function Change({ value }: { value: number | null | undefined }) {
  if (value === null || value === undefined) return <span className="muted" title="Not seen in the previous period">new</span>;
  const rounded = Math.round(value);
  if (rounded === 0) return <span className="muted">0%</span>;
  return <span className={rounded > 0 ? 'delta good' : 'delta bad'}>{rounded > 0 ? '▲' : '▼'} {Math.abs(rounded)}%</span>;
}
