// Behaviour tests for public/snow.js in a minimal fake browser.
//   node --test tests/tracker/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../public/snow.js', import.meta.url), 'utf8');

function browser({ href = 'https://example.com/pricing?utm_source=hn', attrs = {}, dnt = null,
                   referrer = 'https://news.ycombinator.com/', queue = null, beacon = true } = {}) {
  const sent = [];
  const listeners = {};
  const location = new URL(href);
  const history = {
    pushState(_s, _t, url) { const u = new URL(url, location.href); location.pathname = u.pathname; location.search = u.search; },
  };
  const attributes = { src: 'https://stats.example.org/snow.js', ...attrs };
  const window = {
    location, history,
    screen: { width: 1440, height: 900 },
    navigator: {
      doNotTrack: dnt,
      sendBeacon: beacon ? (url, blob) => { sent.push({ url, blob }); return true; } : undefined,
    },
    fetch: (url, opts) => { sent.push({ url, body: opts.body, fetch: true }); },
    addEventListener: (ev, fn) => { listeners[ev] = fn; },
  };
  if (queue) window.snow = { q: queue };
  const document = {
    currentScript: {
      src: attributes.src,
      getAttribute: (k) => attributes[k] ?? null,
      hasAttribute: (k) => k in attributes,
    },
    referrer, title: 'Pricing', visibilityState: 'visible',
    addEventListener() {},
  };
  class Blob { constructor(parts) { this.text = parts.join(''); } }
  vm.runInNewContext(SOURCE, { window, document, URL, JSON, String, Blob });
  const payloads = () => sent.map((s) => ({ url: s.url, ...JSON.parse(s.blob ? s.blob.text : s.body) }));
  return { window, payloads, sent, listeners };
}

test('sends one pageview with the documented short keys', () => {
  const b = browser();
  assert.deepEqual(b.payloads(), [{
    url: 'https://stats.example.org/api/event',
    n: 'pageview', d: 'example.com', u: 'https://example.com/pricing?utm_source=hn',
    r: 'https://news.ycombinator.com/', t: 'Pricing', w: 1440, h: 900,
  }]);
});

test('endpoint follows a path prefix in the script URL', () => {
  const b = browser({ attrs: { src: 'https://example.com/analytics/snow.js' } });
  assert.equal(b.payloads()[0].url, 'https://example.com/analytics/api/event');
});

test('data-domain and data-api override the defaults', () => {
  const b = browser({ attrs: { 'data-domain': 'site.test', 'data-api': 'https://collect.test/api/event' } });
  assert.equal(b.payloads()[0].d, 'site.test');
  assert.equal(b.payloads()[0].url, 'https://collect.test/api/event');
});

test('custom events carry props; queued calls are replayed', () => {
  const b = browser({ queue: [['signup', { plan: 'pro' }]] });
  b.window.snow('download', { file: 'a.pdf' });
  const names = b.payloads().map((p) => [p.n, p.p]);
  assert.deepEqual(names, [['signup', { plan: 'pro' }], ['pageview', undefined], ['download', { file: 'a.pdf' }]]);
});

test('Do Not Track sends nothing', () => {
  assert.equal(browser({ dnt: '1' }).sent.length, 0);
});

test('localhost is ignored unless data-local is set', () => {
  assert.equal(browser({ href: 'http://localhost:3000/' }).sent.length, 0);
  assert.equal(browser({ href: 'http://localhost:3000/', attrs: { 'data-local': '' } }).sent.length, 1);
});

test('SPA navigation counts each new route once', () => {
  const b = browser();
  b.window.history.pushState({}, '', '/docs');
  b.window.history.pushState({}, '', '/docs');
  b.window.history.pushState({}, '', '/blog');
  assert.deepEqual(b.payloads().map((p) => new URL(p.u).pathname), ['/pricing', '/docs', '/blog']);
});

test('falls back to fetch keepalive without sendBeacon', () => {
  const b = browser({ beacon: false });
  assert.equal(b.sent[0].fetch, true);
});

test('stays within the 1.5 KB gzip budget', () => {
  assert.ok(gzipSync(SOURCE, { level: 9 }).length <= 1536, 'snow.js is over 1.5 KB gzipped');
});
