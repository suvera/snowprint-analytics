import { useCallback, useEffect, useState } from 'react';
import { api, type Attribution, type Site, type User } from './api';
import { Sidebar } from './components/Sidebar';
import { AuthPage } from './pages/AuthPage';
import { SitesPage } from './pages/SitesPage';
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
  if (session.state !== 'user') {
    return (
      <AuthPage
        mode={session.state === 'setup' ? 'setup' : 'login'}
        onSignedIn={(user) => { setSession({ state: 'user', user }); navigate(''); }}
      />
    );
  }

  const [area, site, sectionId] = route.path;
  const signOut = async () => {
    await api.logout().catch(() => undefined);
    setSession({ state: 'anonymous' });
  };

  return (
    <div className="layout">
      <Sidebar sites={sites} site={area === 'site' ? site : undefined} sectionId={sectionId}
        params={route.params} user={session.user} onSignOut={signOut} />
      <main className="content">
        {area === 'site' && site
          ? <SiteView site={site} sectionId={sectionId} params={route.params} publicUrl={publicUrl} user={session.user} />
          : <SitesPage publicUrl={publicUrl} onSitesChanged={loadSites} />}
        <footer className="footer">
          Snowprint · built on <a href="https://github.com/suvera/winter-boot">Winter Boot</a>
          {geoCredit.text && <> · {geoCredit.url ? <a href={geoCredit.url}>{geoCredit.text}</a> : geoCredit.text}</>}
        </footer>
      </main>
    </div>
  );
}
