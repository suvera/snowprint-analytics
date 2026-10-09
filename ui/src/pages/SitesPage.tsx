import { useEffect, useState } from 'react';
import { api, type Site } from '../api';
import { Snippet } from '../components/Snippet';
import { navigate } from '../route';

/** The user's sites; admins can add one and copy its tracking snippet. */
export function SitesPage({ publicUrl, onSitesChanged }: { publicUrl: string; onSitesChanged?: () => void }) {
  const [sites, setSites] = useState<Site[] | null>(null);
  const [canManage, setCanManage] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [adding, setAdding] = useState(false);
  const [added, setAdded] = useState<Site | null>(null);

  const load = () => api.sites().then((r) => { setSites(r.sites); setCanManage(r.can_manage); }).catch((e) => setError(e.message));
  useEffect(() => { load(); }, []);

  return (
    <main>
      <header className="page-head">
        <div className="page-title"><h1>Sites</h1></div>
        <span className="spacer" />
        {canManage && !adding && <button className="btn btn-primary" onClick={() => { setAdding(true); setAdded(null); }}>Add a site</button>}
      </header>
      {error && <div className="error" role="alert">{error}</div>}
      {adding && <AddSite onDone={(site) => { setAdding(false); if (site) { setAdded(site); load(); onSitesChanged?.(); } }} />}
      {added && (
        <div className="card" style={{ marginBottom: 16 }}>
          <strong>{added.domain} added.</strong> Paste this into the <code>&lt;head&gt;</code> of every page:
          <Snippet domain={added.domain} publicUrl={publicUrl} />
          <div className="form-actions">
            <button className="btn" onClick={() => navigate(`site/${encodeURIComponent(added.domain)}/overview`)}>Open dashboard</button>
          </div>
        </div>
      )}
      {sites !== null && sites.length === 0 && !adding && (
        <div className="card empty">{canManage ? 'No sites yet. Add your first site to get a tracking snippet.' : 'No sites have been shared with you yet.'}</div>
      )}
      <div className="sites">
        {sites?.map((site) => (
          <a key={site.id} className="card site-card" href={`#/site/${encodeURIComponent(site.domain)}/overview`}>
            <div className="site-domain">{site.domain}</div>
            <div className="muted small">{site.timezone}</div>
          </a>
        ))}
      </div>
    </main>
  );
}

function AddSite({ onDone }: { onDone: (site: Site | null) => void }) {
  const [domain, setDomain] = useState('');
  const [timezone, setTimezone] = useState(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
  const [error, setError] = useState<string | null>(null);
  const zones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : ['UTC'];

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      onDone((await api.addSite(domain, timezone)).site);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not add the site');
    }
  };

  return (
    <form className="card" style={{ marginBottom: 16 }} onSubmit={submit}>
      <div className="field">
        <label htmlFor="domain">Domain</label>
        <input id="domain" required placeholder="example.com" value={domain} onChange={(e) => setDomain(e.target.value)} />
      </div>
      <div className="field">
        <label htmlFor="tz">Reporting timezone</label>
        <select id="tz" value={timezone} onChange={(e) => setTimezone(e.target.value)}>
          {['UTC', ...zones.filter((z) => z !== 'UTC')].map((z) => <option key={z} value={z}>{z}</option>)}
        </select>
      </div>
      {error && <div className="error" role="alert">{error}</div>}
      <div className="form-actions">
        <button className="btn btn-primary" type="submit">Add site</button>
        <button className="btn" type="button" onClick={() => onDone(null)}>Cancel</button>
      </div>
    </form>
  );
}
