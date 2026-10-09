// Number formats for tiles, axes and tables (en, auto-compact).

const compact = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });
const full = new Intl.NumberFormat('en');

export function formatNumber(value: number): string {
  return Math.abs(value) < 10_000 ? full.format(value) : compact.format(value);
}

export function formatFull(value: number): string {
  return full.format(value);
}

export function formatDuration(seconds: number): string {
  if (seconds < 60) return `${Math.round(seconds)}s`;
  const m = Math.floor(seconds / 60);
  const s = Math.round(seconds % 60);
  if (m < 60) return s ? `${m}m ${s}s` : `${m}m`;
  return `${Math.floor(m / 60)}h ${m % 60}m`;
}

export function formatPercent(value: number): string {
  return `${Math.round(value)}%`;
}

/** Signed change label, e.g. "+12%". Null when the previous period was empty. */
export function formatChange(change: number | null): string | null {
  if (change === null) return null;
  const rounded = Math.round(change);
  return `${rounded > 0 ? '+' : ''}${rounded}%`;
}

/** "Nice" axis ticks from 0 to just above max, about `count` of them. */
export function niceTicks(max: number, count = 4): number[] {
  if (max <= 0) return [0, 1];
  const raw = max / count;
  const magnitude = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= raw) ?? 10 * magnitude;
  const ticks: number[] = [];
  for (let t = 0; t <= max + step * 0.0001; t += step) ticks.push(Math.round(t * 1e6) / 1e6);
  if (ticks[ticks.length - 1] < max) ticks.push(ticks[ticks.length - 1] + step);
  return ticks;
}
