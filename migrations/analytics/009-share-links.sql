-- Public share links (SP-046): read-only dashboards for people without an
-- account. Only a SHA-256 digest of the token is stored; the link is shown
-- once to the admin who created it. password_hash: Argon2id, NULL = no password.

CREATE TABLE IF NOT EXISTS share_links (
    id            BIGSERIAL    PRIMARY KEY,
    site_id       BIGINT       NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
    token_hash    CHAR(64)     NOT NULL UNIQUE,
    label         VARCHAR(255) NOT NULL DEFAULT '',
    password_hash VARCHAR(255),
    created_by    BIGINT       REFERENCES users (id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    last_used_at  TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS share_links_site ON share_links (site_id);

-- Never used; share links replace it (and would have kept the token in clear).
ALTER TABLE sites DROP COLUMN IF EXISTS public_token;
