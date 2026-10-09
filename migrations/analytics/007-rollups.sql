-- Daily rollups (PRD §6.4: raw events are kept N days, rollups forever).
-- One row per site, site-local day, dimension and value; dimension '' with
-- value '' holds the day's totals. Sums (not rates) so days can be added up:
-- bounce rate = bounces / visits, visit duration = duration_sum / visits.

CREATE TABLE IF NOT EXISTS rollup_daily (
    site_id      BIGINT      NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
    day          DATE        NOT NULL,
    dimension    VARCHAR(32) NOT NULL,
    value        TEXT        NOT NULL,
    visitors     INTEGER     NOT NULL,
    visits       INTEGER     NOT NULL,
    pageviews    INTEGER     NOT NULL,
    events       INTEGER     NOT NULL,
    bounces      INTEGER     NOT NULL,
    duration_sum BIGINT      NOT NULL,
    PRIMARY KEY (site_id, dimension, day, value)
);

-- 0 keeps raw events forever.
ALTER TABLE sites ADD CONSTRAINT sites_retention_days_check CHECK (retention_days >= 0);
