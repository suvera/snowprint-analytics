-- One random salt per UTC day for visitor hashing (PRD §9.3). Rows older than
-- yesterday are deleted by the worker, so no salt outlives ~48 hours. Salts
-- are never copied into or joined with `events`.

CREATE TABLE IF NOT EXISTS salts (
    day        DATE        PRIMARY KEY,
    salt       BYTEA       NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
