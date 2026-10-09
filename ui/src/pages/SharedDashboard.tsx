import { useCallback, useEffect, useState } from 'react';
import { api, type SharedSite } from '../api';
import { Logo } from '../components/Logo';
import { Sidebar } from '../components/Sidebar';
import { SiteView } from './site/SiteView';

interface Props {
  token: string;
  sectionId?: string;
  params: URLSearchParams;
  footer: React.ReactNode;
}

type State =
  | { state: 'loading' }
  | { state: 'missing'; message: string }
  | { state: 'locked'; label: string }
  | { state: 'open'; site: SharedSite };

/** A public share link (#/share/<token>): one site's reports, read-only, no account. */
export function SharedDashboard({ token, sectionId, params, footer }: Props) {
  const [state, setState] = useState<State>({ state: 'loading' });

  const load = useCallback(() => {
    api.shareInfo(token)
      .then((r) => setState(r.site ? { state: 'open', site: r.site } : { state: 'locked', label: r.label }))
      .catch((e) => setState({ state: 'missing', message: e instanceof Error ? e.message : 'This link does not work' }));
  }, [token]);
  useEffect(load, [load]);

  if (state.state === 'loading') return null;
  if (state.state === 'missing') {
    return (
      <main className="auth-panel share-gate">
        <div className="auth-card">
          <div className="auth-logo share-logo"><Logo size={32} /> Snowprint</div>
          <h1>Shared dashboard</h1>
          <div className="error" role="alert">{state.message}</div>
          <p className="auth-meta"><a href="#/">Sign in</a> if you have an account.</p>
        </div>
      </main>
    );
  }
  if (state.state === 'locked') return <Unlock token={token} label={state.label} onUnlocked={load} />;

  const site = state.site;
  const section = sectionId === 'settings' ? undefined : sectionId;
  return (
    <div className="layout">
      <Sidebar sites={[]} site={site.domain} sectionId={section} canManage={false} params={params} share={token} />
      <main className="content">
        <SiteView site={site.domain} siteInfo={{ id: 0, domain: site.domain, timezone: site.timezone, retention_days: 0, has_data: site.has_data }}
          sectionId={section} params={params} publicUrl="" share={token} onSitesChanged={load} />
        {footer}
      </main>
    </div>
  );
}

function Unlock({ token, label, onUnlocked }: { token: string; label: string; onUnlocked: () => void }) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.unlockShare(token, password);
      onUnlocked();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not open the dashboard');
      setBusy(false);
    }
  };

  return (
    <main className="auth-panel share-gate">
      <form className="auth-card" onSubmit={submit} aria-label="Shared dashboard password">
        <div className="auth-logo share-logo"><Logo size={32} /> Snowprint</div>
        <h1>{label || 'Shared dashboard'}</h1>
        <p className="lead">This dashboard is protected by a password.</p>
        <div className="field">
          <label htmlFor="share-password">Password</label>
          <input id="share-password" type="password" autoComplete="current-password" required autoFocus
            value={password} onChange={(e) => setPassword(e.target.value)} />
        </div>
        {error && <div className="error" role="alert">{error}</div>}
        <button className="btn btn-primary btn-block" type="submit" disabled={busy}>{busy ? 'Opening…' : 'Open dashboard'}</button>
      </form>
    </main>
  );
}
