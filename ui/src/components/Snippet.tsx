import { useState } from 'react';

interface Props {
  domain: string;
  publicUrl: string;   // configured public tracking URL; '' = the dashboard's origin
}

/** The tracking snippet for one site, with a copy button. */
export function Snippet({ domain, publicUrl }: Props) {
  const [copied, setCopied] = useState(false);
  const base = (publicUrl || window.location.origin).replace(/\/+$/, '');
  const code = `<script defer src="${base}/snow.js" data-domain="${domain}"></script>`;
  return (
    <div>
      <pre className="snippet">{code}</pre>
      <div className="form-actions">
        <button
          className="btn"
          onClick={() => navigator.clipboard?.writeText(code).then(() => setCopied(true)).catch(() => undefined)}
        >
          {copied ? 'Copied' : 'Copy snippet'}
        </button>
        {!publicUrl && (
          <span className="muted small">Tracking URL: this dashboard's address. Set SNOWPRINT_PUBLIC_URL to change it.</span>
        )}
      </div>
    </div>
  );
}
