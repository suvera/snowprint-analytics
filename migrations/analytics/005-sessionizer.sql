-- Assigns events.session_id after ingest (decision D5). A session ends after
-- 30 minutes without events from the same visitor on the same site; its id is
-- the id of its first event. Events are processed in time order from a
-- watermark, at most one hour of events per call, and only once they are a
-- minute old (ingest buffers flush well within that).
--
-- Safe to call from several workers at once: a transaction-level advisory
-- lock lets one caller work while the others return immediately.

CREATE OR REPLACE FUNCTION snowprint_sessionize(
    settle INTERVAL DEFAULT interval '1 minute',
    max_window INTERVAL DEFAULT interval '1 hour'
)
RETURNS TABLE (updated INTEGER, caught_up BOOLEAN)
LANGUAGE plpgsql
AS $$
DECLARE
    gap     CONSTANT INTERVAL := interval '30 minutes';
    horizon TIMESTAMPTZ := now() - settle;
    lower_b TIMESTAMPTZ;
    upper_b TIMESTAMPTZ;
    n       INTEGER;
BEGIN
    IF NOT pg_try_advisory_xact_lock(hashtext('snowprint_sessionize')) THEN
        RETURN QUERY SELECT -1, FALSE;
        RETURN;
    END IF;

    SELECT position INTO lower_b FROM job_watermarks WHERE job = 'sessionizer';
    IF lower_b IS NULL THEN
        SELECT min(ts) INTO lower_b FROM events WHERE session_id IS NULL;
        IF lower_b IS NULL THEN
            RETURN QUERY SELECT 0, TRUE;
            RETURN;
        END IF;
    END IF;
    IF lower_b >= horizon THEN
        RETURN QUERY SELECT 0, TRUE;
        RETURN;
    END IF;
    upper_b := least(lower_b + max_window, horizon);

    WITH batch AS (
        SELECT id, site_id, visitor_hash, ts, NULL::BIGINT AS sid, FALSE AS anchor
        FROM events
        WHERE ts >= lower_b AND ts < upper_b AND session_id IS NULL
    ),
    anchors AS (
        -- Last already-sessionized event per visitor just before the batch,
        -- so sessions continue across batch boundaries.
        SELECT DISTINCT ON (e.site_id, e.visitor_hash)
               e.id, e.site_id, e.visitor_hash, e.ts, e.session_id AS sid, TRUE AS anchor
        FROM events e
        WHERE e.ts >= lower_b - gap AND e.ts < lower_b AND e.session_id IS NOT NULL
          AND EXISTS (SELECT 1 FROM batch b
                      WHERE b.site_id = e.site_id AND b.visitor_hash = e.visitor_hash)
        ORDER BY e.site_id, e.visitor_hash, e.ts DESC, e.id DESC
    ),
    combined AS (
        SELECT * FROM batch UNION ALL SELECT * FROM anchors
    ),
    marked AS (
        SELECT c.*,
               CASE WHEN lag(ts) OVER w IS NULL OR ts - lag(ts) OVER w > gap THEN 1 ELSE 0 END AS is_new
        FROM combined c
        WINDOW w AS (PARTITION BY site_id, visitor_hash ORDER BY ts, id)
    ),
    grouped AS (
        SELECT m.*, sum(is_new) OVER (PARTITION BY site_id, visitor_hash ORDER BY ts, id) AS grp
        FROM marked m
    ),
    assigned AS (
        SELECT id, site_id, ts, anchor,
               first_value(coalesce(sid, id)) OVER (PARTITION BY site_id, visitor_hash, grp ORDER BY ts, id) AS new_sid
        FROM grouped
    )
    UPDATE events e
    SET session_id = a.new_sid
    FROM assigned a
    WHERE NOT a.anchor AND e.site_id = a.site_id AND e.ts = a.ts AND e.id = a.id;
    GET DIAGNOSTICS n = ROW_COUNT;

    INSERT INTO job_watermarks (job, position, updated_at) VALUES ('sessionizer', upper_b, now())
    ON CONFLICT (job) DO UPDATE SET position = EXCLUDED.position, updated_at = now();

    RETURN QUERY SELECT n, upper_b >= horizon;
END;
$$;
