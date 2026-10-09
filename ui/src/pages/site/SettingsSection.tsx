import { useCallback, useEffect, useState } from 'react';
import { api, type ShareLink, type Site } from '../../api';
import { CopyField } from '../../components/CopyField';
import { Snippet } from '../../components/Snippet';
import { navigate } from '../../route';

interface Props {
  site: Site;
  publicUrl: string;
  canDelete: boolean;          // instance admins only
  onChanged: () => void;       // reload the site list
}

/** Site settings: reporting timezone, raw-event retention, snippet, share links, deletion. */
export function SettingsSection({ site, publicUrl, canDelete, onChanged }: Props) {
  return (
    <div className="stack narrow">
      <SiteForm site={site} onChanged={onChanged} />
      <section className="card">
        <div className="panel-head"><span className="panel-title">Tracking snippet</span></div>
        Add this to the <code>&lt;head&gt;</code> of every page on {site.domain}:
        <Snippet domain={site.domain} publicUrl={publicUrl} />
      </section>
      <ShareLinks domain={site.domain} />
      {canDelete && <DeleteSite domain={site.domain} onDeleted={onChanged} />}
    </div>
  );
}

function SiteForm({ site, onChanged }: { site: Site; onChanged: () => void }) {
  const [timezone, setTimezone] = useState(site.timezone);
  const [retention, setRetention] = useState(String(site.retention_days));
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const zones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
  const options = ['UTC', ...zones.filter((z) => z !== 'UTC')];
  if (!options.includes(site.timezone)) options.unshift(site.timezone);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      await api.updateSite(site.domain, { timezone, retention_days: Number(retention) });
      setMessage(timezone !== site.timezone
        ? 'Saved. Daily totals are being rebuilt for the new timezone; this takes a few minutes.'
        : 'Saved.');
      onChanged();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not save');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form className="card" onSubmit={submit} aria-label="Site settings">
      <div className="panel-head"><span className="panel-title">General</span></div>
      <div className="field">
        <label htmlFor="domain">Domain</label>
        <input id="domain" value={site.domain} readOnly aria-describedby="domain-hint" />
        <span className="hint" id="domain-hint">The tracking snippet refers to the domain, so it cannot change.</span>
      </div>
      <div className="field">
        <label htmlFor="tz">Reporting timezone</label>
        <select id="tz" value={timezone} onChange={(e) => setTimezone(e.target.value)}>
          {options.map((z) => <option key={z} value={z}>{z}</option>)}
        </select>
        <span className="hint">Days in reports start at midnight in this timezone.</span>
      </div>
      <div className="field">
        <label htmlFor="retention">Keep raw events (days)</label>
        <input id="retention" type="number" min={0} max={3650} step={1} required value={retention}
          onChange={(e) => setRetention(e.target.value)} aria-describedby="retention-hint" />
        <span className="hint" id="retention-hint">
          0 keeps them forever. Older days stay in the reports as daily totals; filtered reports and
          goals need raw events, so they only reach this far back.
        </span>
      </div>
      {error && <div className="error" role="alert">{error}</div>}
      {message && <div className="notice" role="status">{message}</div>}
      <div className="form-actions">
        <button className="btn btn-primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button>
      </div>
    </form>
  );
}

/** Public read-only links to this site's reports (SP-046). */
function ShareLinks({ domain }: { domain: string }) {
  const [links, setLinks] = useState<ShareLink[] | null>(null);
  const [adding, setAdding] = useState(false);
  const [label, setLabel] = useState('');
  const [password, setPassword] = useState('');
  const [created, setCreated] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    api.shares(domain).then((r) => setLinks(r.shares)).catch((e) => setError(e.message));
  }, [domain]);
  useEffect(load, [load]);

  const add = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    try {
      const { share } = await api.addShare(domain, label, password);
      setCreated(window.location.origin + share.path);
      setAdding(false);
      setLabel('');
      setPassword('');
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not create the link');
    }
  };

  const remove = async (link: ShareLink) => {
    if (!window.confirm(`Delete the share link "${link.label || 'untitled'}"? Everyone using it loses access.`)) return;
    setError(null);
    try {
      await api.deleteShare(domain, link.id);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not delete the link');
    }
  };

  return (
    <section className="card" aria-label="Share links">
      <div className="panel-head">
        <span className="panel-title">Share links</span>
        {!adding && <button className="btn" onClick={() => { setAdding(true); setCreated(null); }}>New link</button>}
      </div>
      <p className="small muted">
        Anyone with a share link can read this site's reports without an account, but cannot change anything.
      </p>
      {created && (
        <div className="notice" role="status">
          Copy the link now: it is not shown again.
          <CopyField value={created} label="Share link" />
        </div>
      )}
      {adding && (
        <form onSubmit={add} aria-label="New share link">
          <div className="field">
            <label htmlFor="share-label">Name</label>
            <input id="share-label" value={label} maxLength={255} placeholder="e.g. Public stats"
              onChange={(e) => setLabel(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="share-pass">Password (optional)</label>
            <input id="share-pass" type="password" autoComplete="new-password" value={password}
              onChange={(e) => setPassword(e.target.value)} aria-describedby="share-pass-hint" />
            <span className="hint" id="share-pass-hint">Leave empty for a link anyone can open.</span>
          </div>
          <div className="form-actions">
            <button className="btn btn-primary" type="submit">Create link</button>
            <button className="btn" type="button" onClick={() => setAdding(false)}>Cancel</button>
          </div>
        </form>
      )}
      {error && <div className="error" role="alert">{error}</div>}
      {links && links.length > 0 && (
        <div className="table-scroll">
          <table className="data">
            <thead><tr><th>Name</th><th>Password</th><th>Last opened</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {links.map((l) => (
                <tr key={l.id}>
                  <td>{l.label || <span className="muted">untitled</span>}</td>
                  <td>{l.has_password ? 'yes' : 'no'}</td>
                  <td className="muted small">{l.last_used_at ? new Date(l.last_used_at).toLocaleString() : 'never'}</td>
                  <td className="num"><button className="btn btn-danger" onClick={() => remove(l)}>Delete</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function DeleteSite({ domain, onDeleted }: { domain: string; onDeleted: () => void }) {
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.deleteSite(domain);
      onDeleted();
      navigate('');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not delete the site');
      setBusy(false);
    }
  };

  return (
    <form className="card danger-zone" onSubmit={submit} aria-label="Delete site">
      <div className="panel-head"><span className="panel-title">Delete this site</span></div>
      <p className="small">
        Deletes {domain} with all its events, daily totals, goals and share links, and removes it from
        users and API keys. This cannot be undone.
      </p>
      <div className="field">
        <label htmlFor="confirm-domain">Type <strong>{domain}</strong> to confirm</label>
        <input id="confirm-domain" value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="off" />
      </div>
      {error && <div className="error" role="alert">{error}</div>}
      <div className="form-actions">
        <button className="btn btn-danger" type="submit" disabled={busy || confirm !== domain}>
          {busy ? 'Deleting…' : 'Delete site'}
        </button>
      </div>
    </form>
  );
}
