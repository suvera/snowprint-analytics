import { useEffect, useState } from 'react';
import { api, type BreakdownRow, type Goal } from '../../api';
import { DataTable } from '../../components/DataTable';
import { formatFull } from '../../format';
import type { SiteContext } from './SiteView';

/** Goal conversions, custom events, and (admins) adding goals. */
export function EventsSection({ ctx, canManage }: { ctx: SiteContext; canManage: boolean }) {
  const [goals, setGoals] = useState<Goal[] | null>(null);
  const [events, setEvents] = useState<BreakdownRow[]>([]);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    Promise.all([
      api.goals(ctx.report, controller.signal),
      api.breakdown(ctx.report, 'event', 100, controller.signal, true),
      api.overview(ctx.report, controller.signal),
    ]).then(([g, e, o]) => { setGoals(g.goals); setEvents(e.rows); setTotal(o.current.visitors); })
      .catch((e) => { if (e.name !== 'AbortError') setError(e.message); });
    return () => controller.abort();
  }, [ctx.report, reload]);

  return (
    <div className="stack">
      {error && <div className="error" role="alert">{error}</div>}
      <section className="card">
        <div className="panel-head"><span className="panel-title">Goals</span></div>
        {goals === null ? <div className="empty">Loading…</div> : goals.length === 0 ? (
          <div className="empty">No goals yet.{canManage ? ' Add one below.' : ''}</div>
        ) : (
          <div className="table-scroll">
            <table className="data wide">
              <thead><tr><th>Goal</th><th>Matches</th><th className="num">Visitors</th><th className="num">Completions</th><th className="num">Conversion rate</th></tr></thead>
              <tbody>
                {goals.map((g) => (
                  <tr key={g.id}>
                    <td>{g.name}</td>
                    <td className="muted">{g.kind === 'pageview' ? `Visit ${g.match}` : `Event “${g.match}”`}</td>
                    <td className="num">{formatFull(g.visitors)}</td>
                    <td className="num">{formatFull(g.completions)}</td>
                    <td className="num">{g.conversion_rate}%</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {canManage && <AddGoal site={ctx.site} onAdded={() => setReload((n) => n + 1)} />}
      </section>
      <section className="card">
        <div className="panel-head"><span className="panel-title">Custom events</span>
          <span className="muted small">sent with <code>snow('name', {'{'}…{'}'})</code></span></div>
        <DataTable rows={events} dimension="event" column="Event" totalVisitors={total} eventsMode
          onFilter={(value) => ctx.filter('event', value)} />
      </section>
    </div>
  );
}

function AddGoal({ site, onAdded }: { site: string; onAdded: () => void }) {
  const [kind, setKind] = useState<'pageview' | 'event'>('pageview');
  const [match, setMatch] = useState('');
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    try {
      await api.addGoal(site, kind, match, name);
      setMatch(''); setName('');
      onAdded();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not add the goal');
    }
  };

  return (
    <form className="inline-form" onSubmit={submit} aria-label="Add a goal">
      <select value={kind} onChange={(e) => setKind(e.target.value as 'pageview' | 'event')} aria-label="Goal type">
        <option value="pageview">Page visit</option>
        <option value="event">Custom event</option>
      </select>
      <input required value={match} onChange={(e) => setMatch(e.target.value)} aria-label={kind === 'pageview' ? 'Path' : 'Event name'}
        placeholder={kind === 'pageview' ? '/thank-you*' : 'signup'} />
      <input value={name} onChange={(e) => setName(e.target.value)} aria-label="Goal name (optional)" placeholder="Name (optional)" />
      <button className="btn btn-primary" type="submit">Add goal</button>
      {error && <div className="error" role="alert">{error}</div>}
    </form>
  );
}
