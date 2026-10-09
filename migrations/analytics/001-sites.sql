CREATE TABLE IF NOT EXISTS sites (
    id             BIGSERIAL PRIMARY KEY,
    domain         VARCHAR(255) NOT NULL UNIQUE,
    timezone       VARCHAR(64)  NOT NULL DEFAULT 'UTC',
    public_token   VARCHAR(64)  UNIQUE,
    retention_days INTEGER      NOT NULL DEFAULT 90,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);
