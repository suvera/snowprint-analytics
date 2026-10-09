-- Users, per-site roles, API keys (MCP and server-side API), goals and
-- annotations (PRD §6.2, §6.3). Login sessions use Winter Boot's
-- PdbcSessionStore table layout.

CREATE TABLE IF NOT EXISTS users (
    id            BIGSERIAL    PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    name          VARCHAR(255) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    is_admin      BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    last_login_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS site_users (
    site_id    BIGINT      NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
    user_id    BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role       VARCHAR(16) NOT NULL CHECK (role IN ('viewer', 'admin')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (site_id, user_id)
);

-- Keys are shown once; only a SHA-256 digest is stored. key_prefix (first
-- characters of the key) identifies a key in lists and logs.
CREATE TABLE IF NOT EXISTS api_keys (
    id           BIGSERIAL    PRIMARY KEY,
    name         VARCHAR(255) NOT NULL,
    key_prefix   VARCHAR(16)  NOT NULL,
    key_hash     CHAR(64)     NOT NULL UNIQUE,
    all_sites    BOOLEAN      NOT NULL DEFAULT FALSE,
    can_write    BOOLEAN      NOT NULL DEFAULT FALSE,
    created_by   BIGINT       REFERENCES users (id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ,
    revoked_at   TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS api_key_sites (
    api_key_id BIGINT NOT NULL REFERENCES api_keys (id) ON DELETE CASCADE,
    site_id    BIGINT NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
    PRIMARY KEY (api_key_id, site_id)
);

CREATE TABLE IF NOT EXISTS goals (
    id         BIGSERIAL    PRIMARY KEY,
    site_id    BIGINT       NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
    name       VARCHAR(255) NOT NULL,
    kind       VARCHAR(16)  NOT NULL CHECK (kind IN ('pageview', 'event')),
    match      VARCHAR(2048) NOT NULL,  -- path (may end with *) or event name
    created_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (site_id, kind, match)
);

CREATE TABLE IF NOT EXISTS annotations (
    id         BIGSERIAL    PRIMARY KEY,
    site_id    BIGINT       NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
    day        DATE         NOT NULL,
    text       VARCHAR(500) NOT NULL,
    created_by BIGINT       REFERENCES users (id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS annotations_site_day_idx ON annotations (site_id, day);

CREATE TABLE IF NOT EXISTS winter_sessions (
    session_id   VARCHAR(128) NOT NULL PRIMARY KEY,
    username     VARCHAR(255) NOT NULL,
    expiry       BIGINT       DEFAULT 0,
    created_at   BIGINT       DEFAULT 0,
    updated_at   BIGINT       DEFAULT 0,
    session_type SMALLINT     DEFAULT 0,
    session_data TEXT
);
