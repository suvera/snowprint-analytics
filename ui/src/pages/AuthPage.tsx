import { useEffect, useState } from 'react';
import { api, type User } from '../api';
import { Logo } from '../components/Logo';

interface Props {
  mode: 'setup' | 'login' | 'invite';
  inviteToken?: string;     // mode 'invite': the token from the invite link
  onSignedIn: (user: User) => void;
}

const CHECK = 'M5 12.5l4.2 4.2L19 7';
const POINTS = [
  'No cookies, no personal data stored',
  'Every number exact, every report filterable',
  'Ask Claude about your traffic over MCP',
  'Self-hosted: one container on your PostgreSQL',
];

const TEXT = {
  setup: { title: 'Create your admin account', lead: 'This is a new Snowprint installation. The first account manages everything.',
    submit: 'Create account', busy: 'Creating account…', meta: 'You can invite teammates later.' },
  invite: { title: 'Join Snowprint', lead: 'You were invited to this Snowprint dashboard. Choose your name and password.',
    submit: 'Create account', busy: 'Creating account…', meta: 'Already have an account? Sign in instead.' },
  login: { title: 'Sign in', lead: 'Welcome back. Sign in to your dashboard.',
    submit: 'Sign in', busy: 'Signing in…', meta: 'Forgot your password? Ask your Snowprint admin to reset it.' },
};

/** Sign-in, first-run creation of the admin account, or accepting an invite. */
export function AuthPage({ mode, inviteToken = '', onSignedIn }: Props) {
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const setup = mode === 'setup';
  const invite = mode === 'invite';
  const creating = setup || invite;
  const text = TEXT[mode];

  useEffect(() => {
    if (!invite) return;
    api.inviteInfo(inviteToken).then((r) => setEmail(r.invite.email)).catch((err) => setError(err.message));
  }, [invite, inviteToken]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const { user } = setup ? await api.setup(email, name, password)
        : invite ? await api.acceptInvite(inviteToken, name, password)
        : await api.login(email, password);
      onSignedIn(user);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Something went wrong');
      setBusy(false);
    }
  };

  return (
    <div className="auth-page">
      <section className="auth-brand" aria-label="Snowprint">
        <div className="auth-logo">
          <Logo size={36} /> Snowprint
        </div>
        <div className="auth-pitch">
          <h2>Privacy-first web analytics</h2>
          <p>Understand your traffic without tracking people. Footprints in fresh snow: visible today, gone tomorrow.</p>
          <ul className="auth-points">
            {POINTS.map((point) => (
              <li key={point}>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4"
                  strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={CHECK} /></svg>
                {point}
              </li>
            ))}
          </ul>
        </div>
        <div className="auth-brand-foot">
          Open source · built on <a href="https://github.com/suvera/winter-boot">Winter Boot</a>
        </div>
      </section>

      <main className="auth-panel">
        <div className="auth-card">
          <h1>{text.title}</h1>
          <p className="lead">{text.lead}</p>
          <form onSubmit={submit} noValidate={false}>
            {creating && (
              <div className="field">
                <label htmlFor="name">Name</label>
                <input id="name" value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" placeholder="Ada Lovelace" />
              </div>
            )}
            <div className="field">
              <label htmlFor="email">Email</label>
              <input id="email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)}
                readOnly={invite} autoComplete={creating ? 'email' : 'username'} placeholder="you@example.com" autoFocus={!invite} />
            </div>
            <div className="field">
              <label htmlFor="password">Password</label>
              <div className="password-wrap">
                <input id="password" type={showPassword ? 'text' : 'password'} required minLength={creating ? 10 : undefined}
                  value={password} onChange={(e) => setPassword(e.target.value)}
                  autoComplete={creating ? 'new-password' : 'current-password'} aria-describedby={creating ? 'password-hint' : undefined} />
                <button type="button" className="password-toggle" onClick={() => setShowPassword((s) => !s)}
                  aria-label={showPassword ? 'Hide password' : 'Show password'}>{showPassword ? 'Hide' : 'Show'}</button>
              </div>
              {creating && <span className="hint" id="password-hint">At least 10 characters.</span>}
            </div>
            {error && <div className="error" role="alert">{error}</div>}
            <button className="btn btn-primary btn-block" type="submit" disabled={busy || (invite && !email)}>
              {busy ? text.busy : text.submit}
            </button>
          </form>
          <p className="auth-meta">{invite ? <a href="#/">{text.meta}</a> : text.meta}</p>
        </div>
      </main>
    </div>
  );
}
