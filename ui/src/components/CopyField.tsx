import { useState } from 'react';

/** A read-only value (link, key) with a Copy button. */
export function CopyField({ value, label }: { value: string; label: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <div className="copy-row">
      <input readOnly value={value} aria-label={label} onFocus={(e) => e.target.select()} />
      <button className="btn" type="button" onClick={() => {
        navigator.clipboard?.writeText(value).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); }).catch(() => undefined);
      }}>{copied ? 'Copied' : 'Copy'}</button>
    </div>
  );
}
