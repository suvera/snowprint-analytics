import type { Site, User } from '../api';
import { navigate } from '../route';
import { SECTIONS } from '../sections';
import { Icon } from './Icon';
import { Logo } from './Logo';

interface Props {
  sites: Site[];
  site?: string;            // current site domain
  sectionId?: string;       // current section
  params: URLSearchParams;  // kept when switching sections (period, filters)
  user: User;
  onSignOut: () => void;
}

const SITES_ICON = 'M4 6h16M4 12h16M4 18h16';

/** Left menu: brand, site switcher, sections, sites and account. */
export function Sidebar({ sites, site, sectionId, params, user, onSignOut }: Props) {
  const keep = new URLSearchParams(params);
  keep.delete('tab');
  const query = keep.toString() ? `?${keep.toString()}` : '';

  return (
    <aside className="sidebar">
      <a className="brand" href="#/">
        <Logo className="brand-mark" />
        Snowprint
      </a>

      {sites.length > 0 && (
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
        {site && SECTIONS.map((s) => (
          <a key={s.id} className="nav-item" aria-current={s.id === (sectionId ?? 'overview') ? 'page' : undefined}
            href={`#/site/${encodeURIComponent(site)}/${s.id}${query}`}>
            <Icon path={s.icon} /> <span>{s.label}</span>
          </a>
        ))}
      </nav>

      <div className="sidebar-foot">
        <a className="nav-item" aria-current={!site ? 'page' : undefined} href="#/">
          <Icon path={SITES_ICON} /> <span>All sites</span>
        </a>
        <div className="account">
          <span className="account-email" title={user.email}>{user.email}</span>
          <button className="btn-link small" onClick={onSignOut}>Sign out</button>
        </div>
      </div>
    </aside>
  );
}
