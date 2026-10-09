-- Raw analytics events, partitioned by month (PRD §9.2).
-- Privacy: visitor_hash is a salted BLAKE2b-128 digest; raw IP addresses and
-- user agents are never stored, in this table or anywhere else.

CREATE TABLE IF NOT EXISTS events (
    id              BIGSERIAL,
    site_id         BIGINT       NOT NULL,
    ts              TIMESTAMPTZ  NOT NULL,
    visitor_hash    BYTEA        NOT NULL,
    session_id      BIGINT       NOT NULL,
    name            VARCHAR(120) NOT NULL,
    hostname        VARCHAR(255),
    path            TEXT         NOT NULL,
    title           TEXT,
    referrer_source VARCHAR(255),
    referrer_host   VARCHAR(255),
    utm_source      VARCHAR(255),
    utm_medium      VARCHAR(255),
    utm_campaign    VARCHAR(255),
    utm_term        VARCHAR(255),
    utm_content     VARCHAR(255),
    country         CHAR(2),
    region          VARCHAR(255),
    city            VARCHAR(255),
    browser         VARCHAR(64),
    browser_version VARCHAR(32),
    os              VARCHAR(64),
    os_version      VARCHAR(32),
    device          VARCHAR(16),
    screen_w        SMALLINT,
    screen_h        SMALLINT,
    props           JSONB
) PARTITION BY RANGE (ts);

CREATE INDEX IF NOT EXISTS events_site_ts_idx ON events (site_id, ts);
CREATE INDEX IF NOT EXISTS events_props_idx ON events USING GIN (props jsonb_path_ops);

-- Safety net: rows outside every monthly partition land here instead of failing.
CREATE TABLE IF NOT EXISTS events_default PARTITION OF events DEFAULT;

-- Creates monthly partitions events_YYYY_MM from the current month up to
-- months_ahead months later. Idempotent; called by migrations and by the
-- worker's partition job.
CREATE OR REPLACE FUNCTION snowprint_ensure_event_partitions(months_ahead INTEGER)
RETURNS INTEGER
LANGUAGE plpgsql
AS $$
DECLARE
    first_month DATE := date_trunc('month', now() AT TIME ZONE 'UTC')::date;
    month_start DATE;
    part_name   TEXT;
    created     INTEGER := 0;
BEGIN
    FOR i IN 0..months_ahead LOOP
        month_start := (first_month + make_interval(months => i))::date;
        part_name := 'events_' || to_char(month_start, 'YYYY_MM');
        IF to_regclass(part_name) IS NULL THEN
            EXECUTE format(
                'CREATE TABLE %I PARTITION OF events FOR VALUES FROM (%L) TO (%L)',
                part_name,
                month_start::timestamp AT TIME ZONE 'UTC',
                (month_start + make_interval(months => 1))::timestamp AT TIME ZONE 'UTC'
            );
            created := created + 1;
        END IF;
    END LOOP;
    RETURN created;
END;
$$;

SELECT snowprint_ensure_event_partitions(2);
