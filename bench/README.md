# Ingest benchmark

`bench/run.sh` measures `POST /api/event` end to end: PostgreSQL 18 and the Snowprint
image run in Docker on one machine (installed the way the README describes), and
[k6](https://k6.io) (`grafana/k6:1.0.0`) sends realistic tracker payloads at a fixed
arrival rate. After the run it checks that every accepted event reached PostgreSQL.

```bash
bench/run.sh                         # 10,000 events/s for 30 s, app limited to 2 CPUs
RATE=20000 DURATION=60s bench/run.sh
APP_CPUS=4 PG_CPUS=4 bench/run.sh
```

Results are written to `bench/results/` (git-ignored).

## Latest results

Intel Core Ultra 9 285, app limited to **2 CPUs with 4 Swoole workers**, PostgreSQL 18
limited to 2 CPUs on the same host, 30 s runs. Every accepted event was stored.

| Commit | Load | Achieved | Failed | Median | p95 | p99 |
|---|---|---|---|---|---|---|
| `19d8314` (no UA parsing) | 8,000/s | 7,985/s | 0 | 0.20 ms | 17.4 ms | 24.7 ms |
| `19d8314` (no UA parsing) | 10,000/s | 9,971/s | 0 | 0.21 ms | 21.7 ms | n/a |
| SP-014 (browser/OS/device) | 10,000/s, ~100 user agents | 9,733/s | 0 | 0.23 ms | 27.6 ms | 51.2 ms |
| SP-014, worst case | 10,000/s, every user agent unique | 3,607/s | 0 | 238 ms | 1.49 s | 2.94 s |
| own image (pgsql coroutines) | 10,000/s | 9,718/s | 0 | 0.22 ms | 26.6 ms | 62.4 ms |
| own image | 6,000/s | 5,857/s | 0 | 0.22 ms | 19.2 ms | 110.9 ms |
| own image, **no CPU limit** | 6,000/s | 5,933/s | 0 | 0.21 ms | 13.2 ms | 24.0 ms |
| Winter Boot 2.1.2, metrics, UA parser warmed at worker start | 10,000/s | 9,815/s | 0 | 0.23 ms | **2.9 ms** | 19.3 ms |
| `591a251` (Winter Boot 2.1.3 from Packagist) | 10,000/s | 9,855/s | 0 | 0.25 ms | 4.6 ms | 29.6 ms |
| `591a251`, **4 CPUs** (app and PostgreSQL) | 20,000/s | 19,756/s | 0 | 0.54 ms | 8.5 ms | 13.3 ms |

- The default load uses ~100 real-shaped user agents: real traffic is dominated by a few
  hundred, and parse results are cached per worker. `UNIQUE_UA=1` defeats that cache
  (device detection then costs ~1 ms of CPU per event).
- Latency is client-side (k6 over loopback).
- **Tail latency is mostly CPU-quota throttling**: `--cpus 2` is a CFS quota (200 ms of CPU
  per 100 ms period); bursts across the 4 workers exhaust it and the container pauses until
  the next period, hence p99 ≈ 100 ms. Without the limit p99 drops to 24 ms. A real 2-vCPU
  VM has no such quota, so these limited runs are a pessimistic stand-in.
- Building Swoole with `--enable-swoole-pgsql` (our image) did not change ingest latency;
  it matters for concurrent report queries, not for the small batch inserts.
- Much of the remaining tail was device detection loading its regex files on first use in
  each worker (and, under concurrency, running out of memory). Warming the parser when a
  worker starts brought p95 from 26.6 ms to 2.9 ms.
