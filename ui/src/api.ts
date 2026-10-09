// Client for the Winter Boot dashboard API (/api/ui). Every request sends the
// X-Snowprint header the server requires on state-changing calls (CSRF guard).

export class ApiError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message);
  }
}

export interface User {
  id: number;
  email: string;
  name: string;
  is_admin: boolean;
}

export interface Attribution {
  text: string;
  url: string;
}

export interface Site {
  id: number;
  domain: string;
  timezone: string;
  retention_days: number;   // raw events kept this many days; 0 = forever
  can_manage?: boolean;     // the user may change settings and goals
  has_data?: boolean;       // the site has received at least one event
}

export type Role = 'viewer' | 'admin';
export type SiteRoles = Record<string, Role>;   // domain => role

export interface Member {
  id: number;
  email: string;
  name: string;
  is_admin: boolean;
  sites: SiteRoles;
  last_login_at: string | null;
}

export interface Invite {
  id: number;
  email: string;
  is_admin: boolean;
  sites: SiteRoles;
  expires_at: string;
}

export interface PeriodInfo {
  from: string;
  to: string;
  timezone: string;
}

export interface Metrics {
  visitors: number;
  visits: number;
  pageviews: number;
  events: number;
  views_per_visit: number;
  bounce_rate: number;
  visit_duration: number;
}

export interface Overview {
  site: string;
  period: PeriodInfo;
  current: Metrics;
  previous: Metrics;
  change: Record<keyof Metrics, number | null>;
}

export interface Point {
  date: string;
  value: number;
}

export interface Series {
  period: PeriodInfo;
  series: Point[];
  previous?: Point[];
}

export interface BreakdownRow {
  value: string;
  visitors: number;
  visits: number;
  pageviews: number;
  events: number;
  bounce_rate: number;
  visit_duration: number;
  previous_visitors?: number | null;
  change?: number | null;
}

export interface Goal {
  id: number;
  name: string;
  kind: 'pageview' | 'event';
  match: string;
  visitors: number;
  completions: number;
  conversion_rate: number;
}

export interface Realtime {
  visitors: number;
  pages: { page: string; visitors: number }[];
}

export type Filters = Record<string, string>;

async function request<T>(method: string, path: string, body?: unknown, signal?: AbortSignal): Promise<T> {
  const response = await fetch(path, {
    method,
    credentials: 'same-origin',
    headers: {
      'X-Snowprint': '1',
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
    signal,
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new ApiError(response.status, typeof data.error === 'string' ? data.error : `Request failed (${response.status})`);
  }
  return data as T;
}

function query(params: Record<string, string | number | undefined>): string {
  const q = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '') q.set(key, String(value));
  }
  return q.toString();
}

export interface ReportParams {
  site: string;
  period: string;
  filters: Filters;
}

function reportQuery(p: ReportParams, extra: Record<string, string | number | undefined> = {}): string {
  return query({
    site: p.site,
    period: p.period,
    filters: Object.keys(p.filters).length ? JSON.stringify(p.filters) : undefined,
    ...extra,
  });
}

export const api = {
  session: () => request<{ setup_required: boolean; user: User | null; public_url: string; geo_attribution?: Attribution }>('GET', '/api/ui/session'),
  setup: (email: string, name: string, password: string) =>
    request<{ user: User }>('POST', '/api/ui/setup', { email, name, password }),
  login: (email: string, password: string) => request<{ user: User }>('POST', '/api/ui/login', { email, password }),
  logout: () => request<unknown>('POST', '/api/ui/logout'),
  sites: () => request<{ sites: Site[]; can_manage: boolean }>('GET', '/api/ui/sites'),
  addSite: (domain: string, timezone: string) => request<{ site: Site }>('POST', '/api/ui/sites', { domain, timezone }),
  updateSite: (domain: string, changes: { timezone?: string; retention_days?: number }) =>
    request<{ site: Site }>('PATCH', `/api/ui/sites/${encodeURIComponent(domain)}`, changes),
  deleteSite: (domain: string) =>
    request<unknown>('DELETE', `/api/ui/sites/${encodeURIComponent(domain)}`, { confirm: domain }),
  users: () => request<{ users: Member[]; invites: Invite[] }>('GET', '/api/ui/users'),
  updateUser: (id: number, isAdmin: boolean, sites: SiteRoles) =>
    request<{ user: User }>('PUT', `/api/ui/users/${id}`, { is_admin: isAdmin, sites }),
  deleteUser: (id: number) => request<unknown>('DELETE', `/api/ui/users/${id}`),
  invite: (email: string, isAdmin: boolean, sites: SiteRoles) =>
    request<{ invite: { id: number; token: string; email: string; expires_at: string } }>('POST', '/api/ui/invites', { email, is_admin: isAdmin, sites }),
  revokeInvite: (id: number) => request<unknown>('DELETE', `/api/ui/invites/${id}`),
  inviteInfo: (token: string) => request<{ invite: { email: string } }>('POST', '/api/ui/invite', { token }),
  acceptInvite: (token: string, name: string, password: string) =>
    request<{ user: User }>('POST', '/api/ui/invite/accept', { token, name, password }),
  addGoal: (domain: string, kind: string, match: string, name: string) =>
    request<{ goal: Goal }>('POST', `/api/ui/sites/${encodeURIComponent(domain)}/goals`, { kind, match, name }),
  overview: (p: ReportParams, signal?: AbortSignal) =>
    request<Overview>('GET', `/api/ui/stats/overview?${reportQuery(p)}`, undefined, signal),
  timeseries: (p: ReportParams, metric: string, signal?: AbortSignal) =>
    request<Series>('GET', `/api/ui/stats/timeseries?${reportQuery(p, { metric, compare: 1 })}`, undefined, signal),
  breakdown: (p: ReportParams, dimension: string, limit = 9, signal?: AbortSignal, compare = false) =>
    request<{ rows: BreakdownRow[] }>('GET', `/api/ui/stats/breakdown?${reportQuery(p, { dimension, limit, compare: compare ? 1 : undefined })}`, undefined, signal),
  goals: (p: ReportParams, signal?: AbortSignal) =>
    request<{ goals: Goal[] }>('GET', `/api/ui/stats/goals?${reportQuery(p)}`, undefined, signal),
  realtime: (site: string, signal?: AbortSignal) =>
    request<Realtime>('GET', `/api/ui/stats/realtime?${query({ site })}`, undefined, signal),
};
