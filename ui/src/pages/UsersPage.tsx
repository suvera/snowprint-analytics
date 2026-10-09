import { useEffect, useState } from 'react';
import { api, type Invite, type Member, type Role, type Site, type SiteRoles, type User } from '../api';
import { CopyField } from '../components/CopyField';

interface Props {
  me: User;
  sites: Site[];
}

/** Instance admins: users, their access, and invite links. */
export function UsersPage({ me, sites }: Props) {
  const [users, setUsers] = useState<Member[] | null>(null);
  const [invites, setInvites] = useState<Invite[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<number | null>(null);
  const [inviting, setInviting] = useState(false);
  const [link, setLink] = useState<{ email: string; url: string } | null>(null);

  const load = () => api.users()
    .then((r) => { setUsers(r.users); setInvites(r.invites); })
    .catch((e) => setError(e.message));
  useEffect(() => { load(); }, []);

  const act = async (action: () => Promise<unknown>) => {
    setError(null);
    try {
      await action();
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Something went wrong');
    }
  };

  return (
    <main>
      <header className="page-head">
        <div className="page-title"><h1>Users</h1></div>
        <span className="spacer" />
        {!inviting && <button className="btn btn-primary" onClick={() => { setInviting(true); setLink(null); }}>Invite a user</button>}
      </header>
      {error && <div className="error" role="alert" style={{ marginBottom: 16 }}>{error}</div>}

      <div className="stack">
        {inviting && (
          <InviteForm sites={sites} onDone={(created) => {
            setInviting(false);
            if (created) {
              setLink({ email: created.email, url: `${window.location.origin}/ui/#/invite/${created.token}` });
              load();
            }
          }} />
        )}
        {link && (
          <section className="card" aria-live="polite">
            <strong>Invite link for {link.email}</strong>
            <p className="small muted">Send it to them yourself: Snowprint does not send email. It works once, for 7 days,
              and is not shown again.</p>
            <CopyField value={link.url} label="Invite link" />
          </section>
        )}

        <section className="card">
          <div className="table-scroll">
            <table className="data">
              <thead><tr><th>User</th><th>Access</th><th>Last sign-in</th><th><span className="sr-only">Actions</span></th></tr></thead>
              <tbody>
                {users === null && <tr><td colSpan={4} className="empty">Loading…</td></tr>}
                {users?.map((u) => editing === u.id ? (
                  <tr key={u.id}><td colSpan={4}>
                    <AccessForm member={u} sites={sites} isSelf={u.id === me.id}
                      onCancel={() => setEditing(null)}
                      onSave={(isAdmin, roles) => act(async () => { await api.updateUser(u.id, isAdmin, roles); setEditing(null); })} />
                  </td></tr>
                ) : (
                  <tr key={u.id}>
                    <td>
                      <div>{u.name || u.email}{u.id === me.id && <span className="muted small"> (you)</span>}</div>
                      {u.name && <div className="muted small">{u.email}</div>}
                    </td>
                    <td>{describeAccess(u.is_admin, u.sites)}</td>
                    <td className="muted small">{u.last_login_at ? new Date(u.last_login_at).toLocaleString() : 'never'}</td>
                    <td className="num">
                      <span className="form-actions" style={{ margin: 0, justifyContent: 'flex-end' }}>
                        <button className="btn" onClick={() => setEditing(u.id)}>Edit</button>
                        {u.id !== me.id && (
                          <button className="btn btn-danger" onClick={() => {
                            if (window.confirm(`Delete ${u.email}? They lose access at once.`)) act(() => api.deleteUser(u.id));
                          }}>Delete</button>
                        )}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        {invites.length > 0 && (
          <section className="card">
            <div className="panel-head"><span className="panel-title">Open invites</span></div>
            <div className="table-scroll">
              <table className="data">
                <thead><tr><th>Email</th><th>Access</th><th>Expires</th><th><span className="sr-only">Actions</span></th></tr></thead>
                <tbody>
                  {invites.map((i) => (
                    <tr key={i.id}>
                      <td>{i.email}</td>
                      <td>{describeAccess(i.is_admin, i.sites)}</td>
                      <td className="muted small">{new Date(i.expires_at).toLocaleDateString()}</td>
                      <td className="num"><button className="btn" onClick={() => act(() => api.revokeInvite(i.id))}>Revoke</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
      </div>
    </main>
  );
}

export function describeAccess(isAdmin: boolean, sites: SiteRoles): string {
  if (isAdmin) return 'Admin of everything';
  const entries = Object.entries(sites);
  if (entries.length === 0) return 'No sites';
  return entries.map(([domain, role]) => `${domain} (${role})`).join(', ');
}

/** Admin checkbox plus a role (none, viewer, admin) per site. */
function AccessFields({ sites, isAdmin, roles, onChange, disableAdmin }: {
  sites: Site[]; isAdmin: boolean; roles: SiteRoles; disableAdmin?: boolean;
  onChange: (isAdmin: boolean, roles: SiteRoles) => void;
}) {
  return (
    <>
      <label className="check">
        <input type="checkbox" checked={isAdmin} disabled={disableAdmin} onChange={(e) => onChange(e.target.checked, roles)} />
        Admin: manages every site, users and invites
      </label>
      {!isAdmin && (
        <fieldset className="field" style={{ border: 0, padding: 0, margin: '14px 0 0' }}>
          <legend className="small muted">Sites</legend>
          {sites.length === 0 ? <span className="muted small">No sites yet.</span> : (
            <div className="roles">
              {sites.map((s) => (
                <RoleRow key={s.id} domain={s.domain} role={roles[s.domain]} onChange={(role) => {
                  const next = { ...roles };
                  if (role) next[s.domain] = role; else delete next[s.domain];
                  onChange(isAdmin, next);
                }} />
              ))}
            </div>
          )}
        </fieldset>
      )}
    </>
  );
}

function RoleRow({ domain, role, onChange }: { domain: string; role?: Role; onChange: (role: Role | undefined) => void }) {
  return (
    <>
      <span className="site-name">{domain}</span>
      <select aria-label={`Access to ${domain}`} value={role ?? ''} onChange={(e) => onChange((e.target.value || undefined) as Role | undefined)}>
        <option value="">No access</option>
        <option value="viewer">Viewer: reports</option>
        <option value="admin">Admin: reports, goals, settings</option>
      </select>
    </>
  );
}

function AccessForm({ member, sites, isSelf, onSave, onCancel }: {
  member: Member; sites: Site[]; isSelf: boolean;
  onSave: (isAdmin: boolean, roles: SiteRoles) => void; onCancel: () => void;
}) {
  const [isAdmin, setIsAdmin] = useState(member.is_admin);
  const [roles, setRoles] = useState<SiteRoles>({ ...member.sites });
  return (
    <form onSubmit={(e) => { e.preventDefault(); onSave(isAdmin, isAdmin ? {} : roles); }} aria-label={`Access for ${member.email}`}>
      <strong>{member.name || member.email}</strong>
      <AccessFields sites={sites} isAdmin={isAdmin} roles={roles} disableAdmin={isSelf && member.is_admin}
        onChange={(a, r) => { setIsAdmin(a); setRoles(r); }} />
      {isSelf && member.is_admin && <span className="hint">You cannot remove your own admin rights.</span>}
      <div className="form-actions">
        <button className="btn btn-primary" type="submit">Save</button>
        <button className="btn" type="button" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

function InviteForm({ sites, onDone }: { sites: Site[]; onDone: (created: { email: string; token: string } | null) => void }) {
  const [email, setEmail] = useState('');
  const [isAdmin, setIsAdmin] = useState(false);
  const [roles, setRoles] = useState<SiteRoles>({});
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    try {
      const { invite } = await api.invite(email, isAdmin, isAdmin ? {} : roles);
      onDone(invite);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not create the invite');
    }
  };

  return (
    <form className="card" onSubmit={submit} aria-label="Invite a user">
      <div className="field">
        <label htmlFor="invite-email">Email</label>
        <input id="invite-email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} placeholder="teammate@example.com" />
      </div>
      <AccessFields sites={sites} isAdmin={isAdmin} roles={roles} onChange={(a, r) => { setIsAdmin(a); setRoles(r); }} />
      {error && <div className="error" role="alert">{error}</div>}
      <div className="form-actions">
        <button className="btn btn-primary" type="submit">Create invite link</button>
        <button className="btn" type="button" onClick={() => onDone(null)}>Cancel</button>
      </div>
    </form>
  );
}
