-- Leases for Winter Boot's #[Lockable] (PdoLockManager, bean "pgLockManager" in
-- infra/LockConfig): rollups and retention run on one pod at a time, also with
-- several worker replicas. Layout from Winter Boot's PdoLockStore; created here
-- because the app's database user need not be allowed to create tables.

CREATE TABLE IF NOT EXISTS winter_locks (
    lock_name  VARCHAR(191) NOT NULL PRIMARY KEY,
    owner      VARCHAR(64)  NOT NULL,
    expires_at BIGINT       NOT NULL    -- epoch milliseconds; 0 = until released
);
