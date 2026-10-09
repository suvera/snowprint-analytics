const PRESETS: [string, string][] = [
  ['today', 'Today'],
  ['yesterday', 'Yesterday'],
  ['7d', 'Last 7 days'],
  ['30d', 'Last 30 days'],
  ['90d', 'Last 90 days'],
  ['month', 'This month'],
  ['last_month', 'Last month'],
  ['12mo', 'Last 12 months'],
];

interface Props {
  value: string;
  onChange: (period: string) => void;
}

/** Date range first, presets before a custom range (YYYY-MM-DD..YYYY-MM-DD). */
export function PeriodPicker({ value, onChange }: Props) {
  const isPreset = PRESETS.some(([key]) => key === value);
  return (
    <label className="small" style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
      <span className="muted">Period</span>
      <select
        className="btn"
        value={isPreset ? value : 'custom'}
        onChange={(e) => {
          if (e.target.value !== 'custom') { onChange(e.target.value); return; }
          const range = window.prompt('Custom range (YYYY-MM-DD..YYYY-MM-DD)', isPreset ? '' : value);
          if (range) onChange(range.trim());
        }}
      >
        {PRESETS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        <option value="custom">{isPreset ? 'Custom…' : value}</option>
      </select>
    </label>
  );
}
