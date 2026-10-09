/*! Snowprint tracker | MIT | https://github.com/suvera/snowprint-analytics */
// No cookies, no localStorage, no fingerprinting APIs. Sends one small JSON
// beacon per pageview or custom event to the Snowprint server that served
// this script.
//
//   <script defer src="https://stats.example.com/snow.js"></script>
//   data-domain="example.com"   site domain as registered (default: page host)
//   data-api="https://…/api/event"  endpoint (default: api/event next to this script)
//   data-local                  also track localhost / file: pages
//
// Custom events: snow('signup', {plan: 'pro'})
// Calls made before the script loads are queued with:
//   <script>window.snow=window.snow||function(){(snow.q=snow.q||[]).push(arguments)}</script>
(function (w, d) {
  'use strict';
  var s = d.currentScript;
  if (!s) return;
  var loc = w.location;
  // Relative to the script, so a Snowprint behind a path prefix (/analytics/snow.js) works.
  var api = s.getAttribute('data-api') || new URL('api/event', s.src).href;
  var domain = s.getAttribute('data-domain') || loc.hostname;
  var last;

  function ignored() {
    if (w.navigator.doNotTrack === '1' || w.doNotTrack === '1') return true;
    if (w._phantom || w.__nightmare || w.navigator.webdriver) return true;
    return !s.hasAttribute('data-local') &&
      (/^(localhost|127\.|\[::1\])/.test(loc.hostname) || loc.protocol === 'file:');
  }

  function send(name, props) {
    if (ignored()) return;
    var body = JSON.stringify({
      n: name,
      d: domain,
      u: loc.href,
      r: d.referrer || null,
      t: d.title || null,
      w: w.screen.width,
      h: w.screen.height,
      p: props || undefined
    });
    // text/plain keeps this a "simple" request: no CORS preflight.
    var blob = new Blob([body], { type: 'text/plain' });
    if (!(w.navigator.sendBeacon && w.navigator.sendBeacon(api, blob))) {
      w.fetch && w.fetch(api, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'text/plain' } });
    }
  }

  function pageview() {
    // Same URL twice in a row (hash changes, replaceState) is one pageview.
    if (last === loc.pathname + loc.search) return;
    last = loc.pathname + loc.search;
    send('pageview');
  }

  // Single-page apps: count route changes made with the History API.
  var push = w.history.pushState;
  if (push) {
    w.history.pushState = function () {
      push.apply(this, arguments);
      pageview();
    };
    w.addEventListener('popstate', pageview);
  }

  var queue = (w.snow && w.snow.q) || [];
  w.snow = function (name, props) {
    send(String(name), props && typeof props === 'object' ? props : undefined);
  };
  for (var i = 0; i < queue.length; i++) w.snow.apply(null, queue[i]);

  if (d.visibilityState === 'prerender') {
    d.addEventListener('visibilitychange', function f() {
      if (d.visibilityState === 'visible') { d.removeEventListener('visibilitychange', f); pageview(); }
    });
  } else {
    pageview();
  }
})(window, document);
