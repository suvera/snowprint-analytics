# Privacy in Snowprint

This page lists exactly what Snowprint receives, what it keeps, and for how long, so you can
judge it against your own obligations (GDPR, ePrivacy, CCPA and others). It describes how the
software works; it is not legal advice. Whether you need consent depends on your jurisdiction,
your other tools and how you configure Snowprint.

## In short

- No cookies, no `localStorage`, no fingerprinting scripts. The tracker sends one small request
  per pageview or custom event and keeps nothing in the browser.
- IP addresses and user agents are used **in memory only**, while the request is handled, and
  are never written to the database or (by default) to logs.
- Visitors are counted with a hash that changes every day. Once a day's salt is deleted, no one,
  including you, can link that day's visits to a person or to their other days.
- Raw events are deleted after each site's retention period (90 days by default). Daily totals
  are kept.

## The visitor hash

For every event Snowprint computes

```
visitor_hash = BLAKE2b-128( daily_salt ‖ site_id ‖ IP address ‖ user agent )
```

- **daily_salt** is 32 random bytes, one per UTC day, stored in the `salts` table (so every
  replica uses the same one) and deleted after 48 hours. It is never stored next to events.
- **site_id** is part of the input, so the same browser gets unrelated hashes on two sites.
- The IP and user agent are not stored. Without the salt the hash cannot be recomputed or
  reversed by trying likely IP addresses.

Consequences you should know about: a person who comes back on another day counts as a new
visitor ("daily-unique visitors"); people behind one shared IP with the same browser version
(an office, carrier-grade NAT) count as one visitor.

## What is stored per event

Table `events`, one row per pageview or custom event:

| Field | Source | Notes |
|---|---|---|
| time, site | server | |
| visitor hash | computed | see above |
| session id | computed later | groups events of one visitor that are less than 30 minutes apart; the id of the session's first event, not a cookie |
| event name | tracker | `pageview` or your custom event name |
| hostname, path | page URL | **query string and fragment are dropped**, except the five `utm_*` parameters |
| page title | `document.title` | |
| referrer host and source | referrer URL | only the host (e.g. `news.ycombinator.com`) and a source name; never the full referrer URL; empty for in-site navigation |
| UTM source, medium, campaign, term, content | page URL | |
| country, region, city | IP address | looked up in a local GeoIP database file when one is configured; the IP is not kept |
| browser, OS (with versions), device type | user agent | the user agent itself is not kept |
| screen width and height | tracker | |
| custom properties | `snow('name', {…})` | **whatever your pages send**: do not put names, emails or other personal data here |

Not collected: IP addresses, full user agents, cookies or device identifiers, full URLs with
query strings, full referrer URLs, keystrokes, mouse movements or page content.

Requests from browsers that send **Do Not Track**, from `localhost` (unless `data-local` is
set) and from recognised bots are not tracked.

## Retention

- **Raw events** are kept for the site's retention period: 90 days by default, from 0
  (forever) to 3650 days, set on the site's Settings page or with
  `bin/console.sh site:set <domain> retention <days>`. An hourly job deletes older events.
- **Daily totals** (`rollup_daily`): per day, the number of visitors, visits, pageviews,
  events, bounces and total visit time, per page, source, country, browser and so on. They
  contain no hashes or session ids and are kept until the site is deleted.
- **Salts** are deleted after 48 hours.
- **Deleting a site** removes its events, daily totals, goals and access grants at once.

## Dashboard users and API keys

- Users: email, name, an Argon2id password hash, admin flag and site roles, last sign-in time.
- Sign-in sessions: a random session id in a cookie (`HttpOnly`, `SameSite=Lax`, and `Secure`
  with `SNOWPRINT_SECURE_COOKIES=true`) and the session row in the database, deleted on sign-out. This cookie is for
  the dashboard only; visitors of tracked sites never get one.
- Invite links and API keys: only SHA-256 digests are stored; the secret is shown once.
- MCP calls are logged with the tool, site, key id and duration, never the arguments.

## Logs

By default Snowprint logs no IP addresses, user agents or tracking payloads. Two debugging
switches change that, so keep them off in production:

- `SNOWPRINT_LOG_CLIENT_IP=true` (Helm: `logClientIp`) logs the client IP of each tracking
  request, to check proxy headers.
- `SNOWPRINT_REQUEST_TRACE=true` (Helm: `requestTrace`) logs one line per request with its
  method, path and status.

Your reverse proxy, load balancer or CDN may log IP addresses on its own; configure those
separately.

## Requests from visitors

Because Snowprint stores no identifier that can be tied to a person, it cannot find "the data
of visitor X" to export or delete it, and after 48 hours nobody can. To remove data for a page
or a period, delete the site or shorten its retention.
