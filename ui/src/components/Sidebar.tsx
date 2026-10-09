import type { Site, User } from '../api';
import { navigate } from '../route';
import { visibleSections } from '../sections';
import { Icon } from './Icon';
import { Logo } from './Logo';

interface Props {
  sites: Site[];
  site?: string;            // current site domain
  sectionId?: string;       // current section
  area?: string;            // first route segment: site, users or none
  canManage: boolean;       // the user manages the current site
  params: URLSearchParams;  // kept when switching sections (period, filters)
  user?: User;              // absent on a share link
  share?: string;           // share-link token: no site list, no account
  onSignOut?: () => void;
}

const SITES_ICON = 'M4 6h16M4 12h16M4 18h16';
const USERS_ICON = 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1M16 3.1a4 4 0 0 1 0 7.8M22 21v-1a6 6 0 0 0-4-5.7';

/** Left menu: brand, site switcher, sections, sites and account. */
export function Sidebar({ sites, site, sectionId, area, canManage, params, user, share, onSignOut }: Props) {
  const base = share ? `share/${share}` : `site/${encodeURIComponent(site ?? '')}`;
  const keep = new URLSearchParams(params);
  keep.delete('tab');
  const query = keep.toString() ? `?${keep.toString()}` : '';

  return (
    <aside className="sidebar">
      <a className="brand" href={share ? `#/${base}` : '#/'}>
        <Logo className="brand-mark" />
        Snowprint
      </a>

      {!share && sites.length > 0 && (
        <label className="site-switch">
          <span className="sr-only">Site</span>
          <select
            value={site ?? ''}
            onChange={(e) => {
              const period = params.get('period');
              const next = new URLSearchParams(period ? { period } : {});
              navigate(`site/${encodeURIComponent(e.target.value)}/${sectionId ?? 'overview'}`, next);
            }}
          >
            {!site && <option value="" disabled>Choose a site…</option>}
            {sites.map((s) => <option key={s.id} value={s.domain}>{s.domain}</option>)}
          </select>
        </label>
      )}

      <nav className="nav" aria-label="Sections">
        {site && visibleSections(canManage).map((s) => (
          <a key={s.id} className="nav-item" aria-current={s.id === (sectionId ?? 'overview') ? 'page' : undefined}
            href={`#/${base}/${s.id}${query}`}>
            <Icon path={s.icon} /> <span>{s.label}</span>
          </a>
        ))}
      </nav>

      {share && (
        <div className="sidebar-foot">
          <div className="account">
            <span className="account-email">Read-only view</span>
            <a className="btn-link small" href="#/">Sign in</a>
          </div>
        </div>
      )}
      {user && <div className="sidebar-foot">
        <a className="nav-item" aria-current={!site && area !== 'users' ? 'page' : undefined} href="#/">
          <Icon path={SITES_ICON} /> <span>All sites</span>
        </a>
        {user.is_admin && (
          <a className="nav-item" aria-current={area === 'users' ? 'page' : undefined} href="#/users">
            <Icon path={USERS_ICON} /> <span>Users</span>
          </a>
        )}
        <div className="account">
          <span className="account-email" title={user.email}>{user.email}</span>
          <button className="btn-link small" onClick={onSignOut}>Sign out</button>
        </div>
      </div>}
    </aside>
  );
}
