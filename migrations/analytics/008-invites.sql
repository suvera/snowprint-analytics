-- Invite links for new dashboard users (SP-019). Only a SHA-256 digest of the
-- token is stored; the link is shown once to the admin who created it.
-- site_roles: JSON object {"<site id>": "viewer" | "admin"}.

CREATE TABLE IF NOT EXISTS invites (
    id          BIGSERIAL    PRIMARY KEY,
    email       VARCHAR(255) NOT NULL,
    token_hash  CHAR(64)     NOT NULL UNIQUE,
    is_admin    BOOLEAN      NOT NULL DEFAULT FALSE,
    site_roles  JSONB        NOT NULL DEFAULT '{}',
    created_by  BIGINT       REFERENCES users (id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    expires_at  TIMESTAMPTZ  NOT NULL,
    accepted_at TIMESTAMPTZ
);

-- Deleted sites whose late events (ingest caches the site list for up to 30 s)
-- the retention job still removes.
CREATE TABLE IF NOT EXISTS deleted_sites (
    site_id    BIGINT      PRIMARY KEY,
    deleted_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
