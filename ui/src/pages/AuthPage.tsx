import { useState } from 'react';
import { api, type User } from '../api';
import { Logo } from '../components/Logo';

interface Props {
  mode: 'setup' | 'login';
  onSignedIn: (user: User) => void;
}

const CHECK = 'M5 12.5l4.2 4.2L19 7';
const POINTS = [
  'No cookies, no personal data stored',
  'Every number exact, every report filterable',
  'Ask Claude about your traffic over MCP',
  'Self-hosted: one container on your PostgreSQL',
];

/** Sign-in, or first-run creation of the admin account. */
export function AuthPage({ mode, onSignedIn }: Props) {
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const setup = mode === 'setup';

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const { user } = setup ? await api.setup(email, name, password) : await api.login(email, password);
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
          <h1>{setup ? 'Create your admin account' : 'Sign in'}</h1>
          <p className="lead">{setup ? 'This is a new Snowprint installation. The first account manages everything.' : 'Welcome back. Sign in to your dashboard.'}</p>
          <form onSubmit={submit} noValidate={false}>
            {setup && (
              <div className="field">
                <label htmlFor="name">Name</label>
                <input id="name" value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" placeholder="Ada Lovelace" />
              </div>
            )}
            <div className="field">
              <label htmlFor="email">Email</label>
              <input id="email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)}
                autoComplete={setup ? 'email' : 'username'} placeholder="you@example.com" autoFocus />
            </div>
            <div className="field">
              <label htmlFor="password">Password</label>
              <div className="password-wrap">
                <input id="password" type={showPassword ? 'text' : 'password'} required minLength={setup ? 10 : undefined}
                  value={password} onChange={(e) => setPassword(e.target.value)}
                  autoComplete={setup ? 'new-password' : 'current-password'} aria-describedby={setup ? 'password-hint' : undefined} />
                <button type="button" className="password-toggle" onClick={() => setShowPassword((s) => !s)}
                  aria-label={showPassword ? 'Hide password' : 'Show password'}>{showPassword ? 'Hide' : 'Show'}</button>
              </div>
              {setup && <span className="hint" id="password-hint">At least 10 characters.</span>}
            </div>
            {error && <div className="error" role="alert">{error}</div>}
            <button className="btn btn-primary btn-block" type="submit" disabled={busy}>
              {busy ? (setup ? 'Creating account…' : 'Signing in…') : (setup ? 'Create account' : 'Sign in')}
            </button>
          </form>
          <p className="auth-meta">{setup ? 'You can invite teammates later.' : 'Forgot your password? Ask your Snowprint admin to reset it.'}</p>
        </div>
      </main>
    </div>
  );
}
