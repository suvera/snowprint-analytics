import type { BreakdownRow } from '../api';
import { formatFull, formatNumber } from '../format';
import { displayValue } from '../sections';

interface Props {
  rows: BreakdownRow[];
  dimension: string;
  limit?: number;
  onFilter?: (value: string) => void;
}

/**
 * Top-N horizontal bars (magnitude, one hue): thin bars from a shared baseline,
 * label above each bar, value at the tip. Text stays in ink tokens.
 */
export function BarList({ rows, dimension, limit = 10, onFilter }: Props) {
  const top = rows.slice(0, limit);
  const max = Math.max(1, ...top.map((r) => r.visitors));
  if (top.length === 0) return <div className="empty">No data for this period</div>;
  return (
    <ol className="barlist">
      {top.map((r) => {
        const label = displayValue(dimension, r.value);
        const clickable = onFilter && r.value !== '(none)';
        return (
          <li key={r.value} className="barlist-row">
            <div className="barlist-label">
              {clickable ? <button className="value-link" onClick={() => onFilter!(r.value)}>{label}</button> : label}
            </div>
            <div className="barlist-track" title={`${label}: ${formatFull(r.visitors)} visitors`}>
              <span className="barlist-bar" style={{ width: `${Math.max(0.5, (r.visitors / max) * 100)}%` }} />
              <span className="barlist-value">{formatNumber(r.visitors)}</span>
            </div>
          </li>
        );
      })}
    </ol>
  );
}
