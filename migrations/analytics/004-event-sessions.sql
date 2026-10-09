-- Sessions are assigned after ingest by the worker's sessionizer (decision D5),
-- so ingest stays stateless. A BRIN index lets it walk events in time order
-- cheaply without slowing inserts.

ALTER TABLE events ALTER COLUMN session_id DROP NOT NULL;

CREATE INDEX IF NOT EXISTS events_ts_brin ON events USING BRIN (ts);

CREATE TABLE IF NOT EXISTS job_watermarks (
    job        VARCHAR(64) PRIMARY KEY,
    position   TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
