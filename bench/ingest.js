// k6 load test for POST /api/event (SP-008). Fixed arrival rate, realistic
// payloads and browser user agents. Run through bench/run.sh.
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE_URL || 'http://localhost:17670';
const RATE = parseInt(__ENV.RATE || '10000', 10);
const DURATION = __ENV.DURATION || '30s';

export const options = {
  discardResponseBodies: true,
  summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
  scenarios: {
    ingest: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: 400,
      maxVUs: 2000,
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.001'],
    'checks{check:accepted}': ['rate>0.999'],
  },
};

// ~100 distinct, real-shaped user agents (browser x version), like real traffic
// where a few hundred agents dominate. UNIQUE_UA=1 makes every request's agent
// unique instead: the worst case for the per-worker parse cache.
const TEMPLATES = [
  (v) => `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${v}.0.0.0 Safari/537.36`,
  (v) => `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${v}.0.0.0 Safari/537.36`,
  (v) => `Mozilla/5.0 (X11; Linux x86_64; rv:${v}.0) Gecko/20100101 Firefox/${v}.0`,
  (v) => `Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${v}.0.0.0 Mobile Safari/537.36`,
  (v) => `Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1 v${v}`,
];
const AGENTS = [];
for (let v = 120; v < 140; v++) for (const t of TEMPLATES) AGENTS.push(t(v));
const UNIQUE_UA = __ENV.UNIQUE_UA === '1';
const PAGES = ['/', '/pricing', '/docs', '/blog/launch', '/signup', '/about'];
const REFERRERS = ['', 'https://news.ycombinator.com/', 'https://www.google.com/', 'https://github.com/'];

export default function () {
  const n = Math.floor(Math.random() * 1e9);
  const body = JSON.stringify({
    d: 'bench.example.com',
    u: `https://bench.example.com${PAGES[n % PAGES.length]}?utm_source=bench`,
    r: REFERRERS[n % REFERRERS.length],
    w: 1440,
    h: 900,
  });
  const res = http.post(`${BASE}/api/event`, body, {
    headers: {
      'Content-Type': 'text/plain',
      'User-Agent': UNIQUE_UA ? `${AGENTS[n % AGENTS.length]} u${n}` : AGENTS[n % AGENTS.length],
    },
  });
  check(res, { accepted: (r) => r.status === 202 }, { check: 'accepted' });
}
