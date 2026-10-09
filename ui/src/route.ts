import { useEffect, useState } from 'react';
import type { Filters } from './api';

// Hash routes keep the dashboard a set of static files (served by Swoole):
//   #/            sites            #/login, #/setup
//   #/site/example.com?period=7d&metric=visitors&f.country=DE
//   #/share/<token>/pages   a public share link (read-only, no account)
// Report state lives in the URL, so every view is shareable and bookmarkable.

export interface Route {
  path: string[];
  params: URLSearchParams;
}

export function parseHash(hash: string): Route {
  const raw = hash.replace(/^#\/?/, '');
  const [path, search = ''] = raw.split('?', 2);
  return { path: path.split('/').filter(Boolean).map(decodeURIComponent), params: new URLSearchParams(search) };
}

export function useRoute(): Route {
  const [route, setRoute] = useState(() => parseHash(window.location.hash));
  useEffect(() => {
    const onChange = () => setRoute(parseHash(window.location.hash));
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);
  return route;
}

export function navigate(path: string, params?: URLSearchParams): void {
  const search = params && params.toString() ? `?${params.toString()}` : '';
  window.location.hash = `#/${path}${search}`;
}

const FILTER_PREFIX = 'f.';

export function filtersFrom(params: URLSearchParams): Filters {
  const filters: Filters = {};
  params.forEach((value, key) => {
    if (key.startsWith(FILTER_PREFIX) && value !== '') filters[key.slice(FILTER_PREFIX.length)] = value;
  });
  return filters;
}

export function withFilter(params: URLSearchParams, dimension: string, value: string | null): URLSearchParams {
  const next = new URLSearchParams(params);
  if (value === null) next.delete(FILTER_PREFIX + dimension);
  else next.set(FILTER_PREFIX + dimension, value);
  return next;
}

export function withParam(params: URLSearchParams, key: string, value: string): URLSearchParams {
  const next = new URLSearchParams(params);
  next.set(key, value);
  return next;
}
