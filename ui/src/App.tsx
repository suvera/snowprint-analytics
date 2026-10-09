import { useCallback, useEffect, useState } from 'react';
import { api, type Attribution, type Site, type User } from './api';
import { Sidebar } from './components/Sidebar';
import { AuthPage } from './pages/AuthPage';
import { SitesPage } from './pages/SitesPage';
import { SharedDashboard } from './pages/SharedDashboard';
import { UsersPage } from './pages/UsersPage';
import { SiteView } from './pages/site/SiteView';
import { navigate, useRoute } from './route';

type Session = { state: 'loading' } | { state: 'setup' } | { state: 'anonymous' } | { state: 'user'; user: User };

export function App() {
  const route = useRoute();
  const [session, setSession] = useState<Session>({ state: 'loading' });
  const [error, setError] = useState<string | null>(null);
  const [publicUrl, setPublicUrl] = useState('');
  const [geoCredit, setGeoCredit] = useState<Attribution>({ text: '', url: '' });
  const [sites, setSites] = useState<Site[]>([]);

  useEffect(() => {
    api.session()
      .then((s) => {
        setPublicUrl(s.public_url ?? '');
        setGeoCredit(s.geo_attribution ?? { text: '', url: '' });
        setSession(s.user ? { state: 'user', user: s.user } : { state: s.setup_required ? 'setup' : 'anonymous' });
      })
      .catch((e) => setError(e.message));
  }, []);

  const loadSites = useCallback(() => {
    api.sites().then((r) => setSites(r.sites)).catch(() => undefined);
  }, []);
  useEffect(() => { if (session.state === 'user') loadSites(); }, [session.state, loadSites]);

  if (error) return <div className="auth-shell"><div className="error" role="alert">Snowprint is not reachable: {error}</div></div>;
  if (session.state === 'loading') return null;
  const footer = (
    <footer className="footer">
      Snowprint · built on <a href="https://github.com/suvera/winter-boot">Winter Boot</a>
      {geoCredit.text && <> · {geoCredit.url ? <a href={geoCredit.url}>{geoCredit.text}</a> : geoCredit.text}</>}
    </footer>
  );
  // A share link opens one site read-only, with or without a session.
  if (route.path[0] === 'share' && route.path[1]) {
    return <SharedDashboard key={route.path[1]} token={route.path[1]} sectionId={route.path[2]} params={route.params} footer={footer} />;
  }
  // An invite link opens the sign-up form even while someone is signed in.
  if (route.path[0] === 'invite' && route.path[1]) {
    return (
      <AuthPage mode="invite" inviteToken={route.path[1]}
        onSignedIn={(user) => { setSession({ state: 'user', user }); navigate(''); }} />
    );
  }
  if (session.state !== 'user') {
    return (
      <AuthPage
        mode={session.state === 'setup' ? 'setup' : 'login'}
        onSignedIn={(user) => { setSession({ state: 'user', user }); navigate(''); }}
      />
    );
  }

  const [area, site, sectionId] = route.path;
  const current = area === 'site' ? sites.find((s) => s.domain === site) : undefined;
  const signOut = async () => {
    await api.logout().catch(() => undefined);
    setSession({ state: 'anonymous' });
  };

  return (
    <div className="layout">
      <Sidebar sites={sites} site={area === 'site' ? site : undefined} sectionId={sectionId} area={area}
        canManage={current?.can_manage ?? false} params={route.params} user={session.user} onSignOut={signOut} />
      <main className="content">
        {area === 'site' && site
          ? <SiteView site={site} siteInfo={current} sectionId={sectionId} params={route.params} publicUrl={publicUrl}
              user={session.user} onSitesChanged={loadSites} />
          : area === 'users' && session.user.is_admin
            ? <UsersPage me={session.user} sites={sites} />
            : <SitesPage publicUrl={publicUrl} onSitesChanged={loadSites} />}
        {footer}
      </main>
    </div>
  );
}
