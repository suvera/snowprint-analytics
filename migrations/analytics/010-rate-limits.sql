-- Shared counters for sign-in lockout, share-link password attempts and the
-- MCP rate limit (infra/RateLimiter). In PostgreSQL rather than worker
-- memory, so limits hold across workers and pods. bucket never contains an
-- email address (sign-in buckets use its SHA-256). Rows are deleted by the
-- housekeeping job a day after their window started.

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket       VARCHAR(100) PRIMARY KEY,
    window_start BIGINT       NOT NULL,   -- Unix seconds
    hits         INTEGER      NOT NULL
);
