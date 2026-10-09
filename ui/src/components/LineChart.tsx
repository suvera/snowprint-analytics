import { useEffect, useMemo, useRef, useState } from 'react';
import type { Point } from '../api';
import { formatFull, formatNumber, niceTicks } from '../format';

interface Props {
  label: string;               // what is plotted, e.g. "Visitors"
  current: Point[];
  previous?: Point[];          // same length; drawn as the grey comparison line
  format?: (value: number) => string;
}

const HEIGHT = 240;
const TOOLTIP_WIDTH = 180;
const PAD = { top: 12, right: 16, bottom: 28, left: 48 };

/**
 * Time series in the accent hue with an optional previous-period line in the
 * de-emphasis grey. Crosshair + tooltip on hover/keyboard; a table view keeps
 * every value reachable without hovering.
 */
export function LineChart({ label, current, previous, format = formatFull }: Props) {
  const wrap = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(720);
  const [hover, setHover] = useState<number | null>(null);
  const [asTable, setAsTable] = useState(false);

  useEffect(() => {
    if (!wrap.current) return;
    const observer = new ResizeObserver(([entry]) => setWidth(Math.max(200, entry.contentRect.width)));
    observer.observe(wrap.current);
    return () => observer.disconnect();
  }, []);

  const hasPrevious = !!previous && previous.length === current.length;
  const { ticks, x, y } = useMemo(() => {
    const values = [...current.map((p) => p.value), ...(hasPrevious ? previous!.map((p) => p.value) : [])];
    const ticks = niceTicks(Math.max(0, ...values));
    const max = ticks[ticks.length - 1];
    const innerW = width - PAD.left - PAD.right;
    const innerH = HEIGHT - PAD.top - PAD.bottom;
    const x = (i: number) => PAD.left + (current.length <= 1 ? innerW / 2 : (i * innerW) / (current.length - 1));
    const y = (v: number) => PAD.top + innerH - (v / max) * innerH;
    return { ticks, x, y };
  }, [current, previous, hasPrevious, width]);

  if (current.length === 0) return <div className="empty">No data for this period</div>;

  const path = (points: Point[]) => points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join('');
  const baseline = y(0);
  const area = `${path(current)}L${x(current.length - 1).toFixed(1)},${baseline}L${x(0).toFixed(1)},${baseline}Z`;
  const labelEvery = Math.max(1, Math.ceil(current.length / Math.max(2, Math.floor(width / 90))));
  const last = current.length - 1;

  const pick = (clientX: number) => {
    const box = wrap.current!.getBoundingClientRect();
    const rel = clientX - box.left;
    let best = 0;
    for (let i = 1; i < current.length; i++) if (Math.abs(x(i) - rel) < Math.abs(x(best) - rel)) best = i;
    setHover(best);
  };

  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowRight') setHover((h) => Math.min(last, (h ?? -1) + 1));
    else if (e.key === 'ArrowLeft') setHover((h) => Math.max(0, (h ?? last + 1) - 1));
    else if (e.key === 'Escape') setHover(null);
    else return;
    e.preventDefault();
  };

  return (
    <div className="chart" ref={wrap}>
      <div className="chart-head">
        {hasPrevious && (
          <div className="legend" aria-label="Legend">
            <span><span className="legend-key" style={{ background: 'var(--accent)' }} />This period</span>
            <span><span className="legend-key" style={{ background: 'var(--previous)' }} />Previous period</span>
          </div>
        )}
        <span className="spacer" />
        <button className="btn-link small" onClick={() => setAsTable((t) => !t)}>
          {asTable ? 'Show chart' : 'Show table'}
        </button>
      </div>

      {asTable ? (
        <table className="data">
          <thead>
            <tr><th>Date</th><th className="num">{label}</th>{hasPrevious && <th className="num">Previous period</th>}</tr>
          </thead>
          <tbody>
            {current.map((p, i) => (
              <tr key={p.date}>
                <td>{p.date}</td>
                <td className="num">{format(p.value)}</td>
                {hasPrevious && <td className="num">{format(previous![i].value)}</td>}
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <svg
          height={HEIGHT}
          role="img"
          aria-label={`${label} over time`}
          tabIndex={0}
          onPointerMove={(e) => pick(e.clientX)}
          onPointerLeave={() => setHover(null)}
          onFocus={() => setHover((h) => h ?? last)}
          onBlur={() => setHover(null)}
          onKeyDown={onKey}
        >
          {ticks.map((t) => (
            <g key={t}>
              <line x1={PAD.left} x2={width - PAD.right} y1={y(t)} y2={y(t)} stroke={t === 0 ? 'var(--axis)' : 'var(--grid)'} strokeWidth={1} />
              <text x={PAD.left - 8} y={y(t)} dy="0.32em" textAnchor="end" fontSize={12} fill="var(--ink-3)" style={{ fontVariantNumeric: 'tabular-nums' }}>
                {formatNumber(t)}
              </text>
            </g>
          ))}
          {current.map((p, i) => (i % labelEvery === 0 || i === last) && (i === last || last - i >= labelEvery / 2) ? (
            <text key={p.date} x={x(i)} y={HEIGHT - 8} textAnchor="middle" fontSize={12} fill="var(--ink-3)">{shortDate(p.date)}</text>
          ) : null)}

          <path d={area} fill="var(--accent)" opacity={0.1} />
          {hasPrevious && <path d={path(previous!)} fill="none" stroke="var(--previous)" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />}
          <path d={path(current)} fill="none" stroke="var(--accent)" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
          {hover === null && <circle cx={x(last)} cy={y(current[last].value)} r={4} fill="var(--accent)" stroke="var(--surface)" strokeWidth={2} />}

          {hover !== null && (
            <g>
              <line x1={x(hover)} x2={x(hover)} y1={PAD.top} y2={baseline} stroke="var(--axis)" strokeWidth={1} />
              {hasPrevious && <circle cx={x(hover)} cy={y(previous![hover].value)} r={4} fill="var(--previous)" stroke="var(--surface)" strokeWidth={2} />}
              <circle cx={x(hover)} cy={y(current[hover].value)} r={4} fill="var(--accent)" stroke="var(--surface)" strokeWidth={2} />
            </g>
          )}
        </svg>
      )}

      {!asTable && hover !== null && (
        <div
          className="tooltip"
          role="status"
          // Beside the crosshair, never over the hovered point: right of it, or left near the edge.
          style={x(hover) + 12 + TOOLTIP_WIDTH <= width
            ? { left: x(hover) + 12, top: 34, width: TOOLTIP_WIDTH }
            : { left: x(hover) - 12 - TOOLTIP_WIDTH, top: 34, width: TOOLTIP_WIDTH }}
        >
          <div className="tooltip-date">{current[hover].date}</div>
          <div className="tooltip-row">
            <span><span className="legend-key" style={{ background: 'var(--accent)' }} />{label}</span>
            <strong>{format(current[hover].value)}</strong>
          </div>
          {hasPrevious && (
            <div className="tooltip-row">
              <span className="muted"><span className="legend-key" style={{ background: 'var(--previous)' }} />{previous![hover].date}</span>
              <strong>{format(previous![hover].value)}</strong>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function shortDate(date: string): string {
  // "2026-10-09" -> "Oct 9", "2026-10-09 14:00" -> "14:00", "2026-10" -> "Oct 2026"
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(date)) return date.slice(11);
  const [y, m, d] = date.split(/[- ]/).map(Number);
  const month = new Date(Date.UTC(y, (m || 1) - 1, d || 1)).toLocaleString('en', { month: 'short', timeZone: 'UTC' });
  return d ? `${month} ${d}` : `${month} ${y}`;
}
